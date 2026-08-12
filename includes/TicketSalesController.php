<?php

declare(strict_types=1);

namespace Dizzy\Reservations;

use Throwable;
use WP_REST_Request;
use WP_REST_Response;

defined('ABSPATH') || exit;

final class TicketSalesController
{
    public function __construct(
        private TicketSalesRepository $repository,
        private TicketSalesService $service,
        private EventGateway $events
    ) {
    }

    public function register(): void
    {
        add_shortcode('dizzy_ticket_checkout', [$this, 'shortcode']);
        add_action('template_redirect', [$this, 'submit'], 5);
        add_action('template_redirect', [$this, 'renderTicket'], 6);
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        register_rest_route(
            'dizzy-reservations/v1',
            '/mollie/webhook',
            [
                'methods' => 'POST',
                'callback' => [$this, 'webhook'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public function shortcode(array $atts = []): string
    {
        $atts = shortcode_atts(['event_id' => get_the_ID()], $atts);
        $eventId = absint($atts['event_id']);
        $occurrences = $this->events->upcoming($eventId);
        $standardPrice = trim(str_replace(',', '.', (string) get_post_meta($eventId, '_dizzy_standard_ticket_price', true)));
        $studentPrice = trim(str_replace(',', '.', (string) get_post_meta($eventId, '_dizzy_student_ticket_price', true)));

        if ($standardPrice === '') {
            $standardPrice = trim(str_replace(',', '.', (string) get_post_meta($eventId, '_dizzy_ticket_price', true)));
        }

        $hasPaidTicket =
            (is_numeric($standardPrice) && (float) $standardPrice > 0)
            || (is_numeric($studentPrice) && (float) $studentPrice > 0);

        if (! $hasPaidTicket) {
            return do_shortcode('[dizzy_reservation_form event_id="' . $eventId . '"]');
        }

        if ($occurrences === []) {
            return '<p>' . esc_html__('No ticket sales date is available for this event.', 'dizzy-reservations-manager') . '</p>';
        }

        $this->repository->syncFromEvent($eventId, (int) $occurrences[0]['id']);
        $types = $this->repository->activeTypes($eventId);

        ob_start();

        $token = isset($_GET['dizzy_order'])
            ? sanitize_text_field(wp_unslash((string) $_GET['dizzy_order']))
            : '';

        if (preg_match('/^[a-f0-9]{64}$/', $token)) {
            $order = $this->repository->orderByToken($token);

            if ($order !== null) {
                try {
                    $order = $this->service->synchronizeOrder($order);
                } catch (Throwable $exception) {
                    error_log('Dizzy Mollie return sync failed: ' . $exception->getMessage());
                }

                $this->renderOrderStatus($order);
            }
        }

        if ($types === []) {
            echo '<p>' . esc_html__('No tickets are currently available for this event.', 'dizzy-reservations-manager') . '</p>';
            return (string) ob_get_clean();
        }
        ?>
        <form method="post" class="dizzy-ticket-checkout">
            <?php wp_nonce_field('dizzy_ticket_purchase', 'dizzy_ticket_nonce'); ?>
            <input type="hidden" name="dizzy_ticket_purchase" value="1">
            <input type="hidden" name="event_id" value="<?php echo esc_attr((string) $eventId); ?>">
            <input type="hidden" name="return_url" value="<?php echo esc_url((string) get_permalink()); ?>">

            <p>
                <label><?php esc_html_e('Ticket', 'dizzy-reservations-manager'); ?><br>
                    <select name="ticket_type_id" required>
                        <?php foreach ($types as $type) : ?>
                            <option value="<?php echo esc_attr((string) $type['id']); ?>">
                                <?php
                                echo esc_html(
                                    sprintf(
                                        '%s — € %s',
                                        (string) $type['name'],
                                        number_format_i18n((float) $type['price'], 2)
                                    )
                                );
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </p>
            <p><label><?php esc_html_e('Quantity', 'dizzy-reservations-manager'); ?><br><input type="number" name="quantity" min="1" max="20" value="1" required></label></p>
            <p><label><?php esc_html_e('Name', 'dizzy-reservations-manager'); ?><br><input name="name" required autocomplete="name"></label></p>
            <p><label><?php esc_html_e('Email', 'dizzy-reservations-manager'); ?><br><input type="email" name="email" required autocomplete="email"></label></p>
            <p><label><?php esc_html_e('Phone', 'dizzy-reservations-manager'); ?><br><input name="phone" autocomplete="tel"></label></p>
            <button type="submit"><?php esc_html_e('Pay with iDEAL', 'dizzy-reservations-manager'); ?></button>
        </form>
        <?php

        return (string) ob_get_clean();
    }

    public function submit(): void
    {
        if (
            ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
            || ! isset($_POST['dizzy_ticket_purchase'])
        ) {
            return;
        }

        $nonce = sanitize_text_field(wp_unslash((string) ($_POST['dizzy_ticket_nonce'] ?? '')));

        if (! wp_verify_nonce($nonce, 'dizzy_ticket_purchase')) {
            wp_die(esc_html__('Invalid ticket checkout request.', 'dizzy-reservations-manager'), '', ['response' => 403]);
        }

        try {
            $result = $this->service->start(wp_unslash($_POST));
            $checkoutUrl = esc_url_raw($result['checkout_url']);
            $checkoutHost = strtolower((string) wp_parse_url($checkoutUrl, PHP_URL_HOST));

            if (
                $checkoutUrl === ''
                || ($checkoutHost !== 'mollie.com' && ! str_ends_with($checkoutHost, '.mollie.com'))
            ) {
                throw new \RuntimeException('Mollie returned an invalid checkout URL.');
            }

            wp_redirect($checkoutUrl, 303, 'Dizzy Reservations Manager');
            exit;
        } catch (Throwable $exception) {
            error_log('Dizzy ticket checkout failed: ' . $exception->getMessage());
            wp_die(
                esc_html__('Ticket checkout could not be started: ', 'dizzy-reservations-manager') .
                esc_html($exception->getMessage()),
                '',
                ['response' => 400]
            );
        }
    }

    public function webhook(WP_REST_Request $request): WP_REST_Response
    {
        $paymentId = sanitize_text_field((string) $request->get_param('id'));

        if (! preg_match('/^tr_[A-Za-z0-9]+$/', $paymentId)) {
            return new WP_REST_Response(['ok' => false], 400);
        }

        try {
            $order = $this->service->synchronize($paymentId);
            return new WP_REST_Response(['ok' => $order !== null], $order !== null ? 200 : 404);
        } catch (Throwable $exception) {
            error_log('Dizzy Mollie webhook failed: ' . $exception->getMessage());
            return new WP_REST_Response(['ok' => false], 500);
        }
    }

    public function renderTicket(): void
    {
        $code = isset($_GET['dizzy_paid_ticket'])
            ? sanitize_text_field(wp_unslash((string) $_GET['dizzy_paid_ticket']))
            : '';

        if (! preg_match('/^[a-f0-9]{64}$/', $code)) {
            return;
        }

        $ticket = $this->repository->ticketByCode($code);

        if ($ticket === null || ($ticket['status'] ?? '') !== 'valid') {
            status_header(404);
            wp_die(esc_html__('Invalid ticket.', 'dizzy-reservations-manager'));
        }

        $checkinResult = '';
        $checkinNonce = isset($_GET['checkin_nonce'])
            ? sanitize_text_field(wp_unslash((string) $_GET['checkin_nonce']))
            : '';

        if (
            current_user_can('manage_options')
            && wp_verify_nonce($checkinNonce, 'dizzy_qr_checkin')
        ) {
            $checkinResult = $this->repository->checkInTicket($code, get_current_user_id());
            $ticket = $this->repository->ticketByCode($code) ?? $ticket;
        }

        $url = $this->service->ticketUrl($code);
        $qr = 'https://api.qrserver.com/v1/create-qr-code/?size=280x280&data=' . rawurlencode($url);
        status_header(200);
        nocache_headers();
        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width">
            <title><?php esc_html_e('Event Ticket', 'dizzy-reservations-manager'); ?></title>
        </head>
        <body style="font-family:sans-serif;max-width:640px;margin:40px auto;padding:24px;text-align:center">
            <h1><?php esc_html_e('Event Ticket', 'dizzy-reservations-manager'); ?></h1>
            <h2><?php echo esc_html(get_the_title((int) $ticket['event_id'])); ?></h2>
            <p><?php echo esc_html((string) $ticket['holder_name']); ?></p>
            <img src="<?php echo esc_url($qr); ?>" width="280" height="280" alt="<?php esc_attr_e('Ticket QR code', 'dizzy-reservations-manager'); ?>">
            <p><code><?php echo esc_html(strtoupper(substr($code, 0, 12))); ?></code></p>
            <?php if ($checkinResult !== '') : ?>
                <p><strong><?php echo esc_html(
                    $checkinResult === 'checked_in'
                        ? __('Check-in completed.', 'dizzy-reservations-manager')
                        : __('Ticket was already checked in or is invalid.', 'dizzy-reservations-manager')
                ); ?></strong></p>
            <?php elseif (! empty($ticket['checked_in_at'])) : ?>
                <p><strong><?php esc_html_e('Checked in', 'dizzy-reservations-manager'); ?></strong></p>
            <?php endif; ?>
            <?php if ($checkinResult !== '') : ?>
                <p><a href="<?php echo esc_url(admin_url('admin.php?page=dizzy-reservations-checkin')); ?>" style="display:inline-block;background:#2271b1;color:#fff;padding:11px 18px;text-decoration:none"><?php esc_html_e('Return to Check-in', 'dizzy-reservations-manager'); ?></a></p>
            <?php endif; ?>
        </body>
        </html>
        <?php
        exit;
    }

    private function renderOrderStatus(array $order): void
    {
        $status = (string) ($order['status'] ?? 'pending');

        if ($status === 'paid') {
            echo '<div class="dizzy-ticket-success"><p><strong>' .
                esc_html__('Payment received. Your tickets are ready.', 'dizzy-reservations-manager') .
                '</strong></p><ul>';

            foreach ($this->repository->ticketsForOrder((int) $order['id']) as $ticket) {
                echo '<li><a href="' . esc_url($this->service->ticketUrl((string) $ticket['ticket_code'])) . '">' .
                    esc_html__('Open ticket', 'dizzy-reservations-manager') .
                    '</a></li>';
            }

            echo '</ul></div>';
            return;
        }

        if (in_array($status, ['failed', 'canceled', 'expired'], true)) {
            echo '<p class="dizzy-ticket-error">' .
                esc_html__('The payment was not completed.', 'dizzy-reservations-manager') .
                '</p>';
            return;
        }

        echo '<p>' . esc_html__('Your payment is being processed. Refresh this page shortly.', 'dizzy-reservations-manager') . '</p>';
    }
}
