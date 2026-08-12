<?php

declare(strict_types=1);

namespace Dizzy\Tickets;

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
        add_shortcode('dizzy_event_ticket_checkout', [$this, 'shortcode']);
        add_action('template_redirect', [$this, 'submit'], 5);
        add_action('template_redirect', [$this, 'renderTicket'], 6);
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        register_rest_route(
            'dizzy-tickets/v1',
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
            return '<p>' . esc_html__('Tickets are not available for this event.', 'dizzy-ticket-manager') . '</p>';
        }

        if ($occurrences === []) {
            return '<p>' . esc_html__('No ticket sales date is available for this event.', 'dizzy-ticket-manager') . '</p>';
        }

        $this->repository->syncFromEvent($eventId, (int) $occurrences[0]['id']);
        $types = $this->repository->activeTypes($eventId);

        ob_start();

        $token = isset($_GET['dizzy_tm_order'])
            ? sanitize_text_field(wp_unslash((string) $_GET['dizzy_tm_order']))
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
            echo '<p>' . esc_html__('No tickets are currently available for this event.', 'dizzy-ticket-manager') . '</p>';
            return (string) ob_get_clean();
        }
        ?>
        <form method="post" class="dizzy-ticket-checkout">
            <?php wp_nonce_field('dizzy_tm_ticket_purchase', 'dizzy_tm_ticket_nonce'); ?>
            <input type="hidden" name="dizzy_tm_ticket_purchase" value="1">
            <input type="hidden" name="event_id" value="<?php echo esc_attr((string) $eventId); ?>">
            <input type="hidden" name="return_url" value="<?php echo esc_url((string) get_permalink()); ?>">

            <p>
                <label><?php esc_html_e('Ticket', 'dizzy-ticket-manager'); ?><br>
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
            <p><label><?php esc_html_e('Quantity', 'dizzy-ticket-manager'); ?><br><input type="number" name="quantity" min="1" max="20" value="1" required></label></p>
            <p><label><?php esc_html_e('Name', 'dizzy-ticket-manager'); ?><br><input name="name" required autocomplete="name"></label></p>
            <p><label><?php esc_html_e('Email', 'dizzy-ticket-manager'); ?><br><input type="email" name="email" required autocomplete="email"></label></p>
            <p><label><?php esc_html_e('Phone', 'dizzy-ticket-manager'); ?><br><input name="phone" autocomplete="tel"></label></p>
            <button type="submit"><?php esc_html_e('Pay with iDEAL', 'dizzy-ticket-manager'); ?></button>
        </form>
        <?php

        return (string) ob_get_clean();
    }

    public function submit(): void
    {
        if (
            ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
            || ! isset($_POST['dizzy_tm_ticket_purchase'])
        ) {
            return;
        }

        $nonce = sanitize_text_field(wp_unslash((string) ($_POST['dizzy_tm_ticket_nonce'] ?? '')));

        if (! wp_verify_nonce($nonce, 'dizzy_tm_ticket_purchase')) {
            wp_die(esc_html__('Invalid ticket checkout request.', 'dizzy-ticket-manager'), '', ['response' => 403]);
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

            wp_redirect($checkoutUrl, 303, 'Dizzy Ticket Manager');
            exit;
        } catch (Throwable $exception) {
            error_log('Dizzy ticket checkout failed: ' . $exception->getMessage());
            wp_die(
                esc_html__('Ticket checkout could not be started: ', 'dizzy-ticket-manager') .
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
        $code = isset($_GET['dizzy_tm_paid_ticket'])
            ? sanitize_text_field(wp_unslash((string) $_GET['dizzy_tm_paid_ticket']))
            : '';

        if (! preg_match('/^[a-f0-9]{64}$/', $code)) {
            return;
        }

        $ticket = $this->repository->ticketByCode($code);

        if ($ticket === null || ($ticket['status'] ?? '') !== 'valid') {
            status_header(404);
            wp_die(esc_html__('Invalid ticket.', 'dizzy-ticket-manager'));
        }

        $checkinResult = '';
        $checkinNonce = isset($_GET['checkin_nonce'])
            ? sanitize_text_field(wp_unslash((string) $_GET['checkin_nonce']))
            : '';

        if (current_user_can('manage_options') && wp_verify_nonce($checkinNonce, 'dizzy_ticket_qr_checkin')) {
            $checkinResult = $this->repository->checkInTicket($code, get_current_user_id());
            $ticket = $this->repository->ticketByCode($code) ?? $ticket;
        }

        global $wpdb;
        $ticketName = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT ticket_name FROM {$wpdb->prefix}dizzy_tm_ticket_order_items WHERE id=%d LIMIT 1",
            (int) $ticket['order_item_id']
        ));
        $start = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT start_datetime FROM {$wpdb->prefix}dizzy_event_occurrences WHERE id=%d LIMIT 1",
            (int) $ticket['occurrence_id']
        ));
        $timestamp = $start !== '' ? strtotime($start) : false;
        $date = $timestamp !== false
            ? wp_date(get_option('date_format') . ' – ' . get_option('time_format'), $timestamp, wp_timezone())
            : $start;
        $url = $this->service->ticketUrl($code);
        $qr = 'https://api.qrserver.com/v1/create-qr-code/?size=560x560&margin=24&data=' . rawurlencode($url);
        $shortCode = strtoupper(substr($code, 0, 12));
        $eventName = get_the_title((int) $ticket['event_id']);
        $holder = (string) $ticket['holder_name'];
        $type = $ticketName !== '' ? $ticketName : __('Event Ticket', 'dizzy-ticket-manager');
        $filename = sanitize_file_name($eventName . '-' . substr($code, 0, 8) . '-ticket.jpg');

        status_header(200);
        nocache_headers();
        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <title><?php esc_html_e('Your Ticket', 'dizzy-ticket-manager'); ?></title>
            <style>
                *{box-sizing:border-box}body{align-items:center;background:#101010;color:#fff;display:flex;font-family:Arial,Helvetica,sans-serif;justify-content:center;margin:0;min-height:100vh;padding:24px;text-align:center}.dizzy-ticket-page{background:#191919;max-width:720px;padding:46px 38px;width:100%}h1{font-size:42px;margin:0 0 28px}h2{font-size:28px;margin:0 0 28px}.dizzy-ticket-date{font-size:16px;margin:0 0 24px}.dizzy-ticket-qr{background:#fff;height:auto;max-width:280px;padding:12px;width:72%}.dizzy-ticket-meta{line-height:1.7;margin:24px 0}.dizzy-ticket-note{margin:22px 0}.dizzy-ticket-actions{display:flex;flex-wrap:wrap;gap:10px;justify-content:center;margin-top:26px}.dizzy-ticket-actions button,.dizzy-ticket-actions a{border:1px solid #fff;border-radius:0;cursor:pointer;font-size:12px;font-weight:700;letter-spacing:1.2px;padding:16px 24px;text-decoration:none;text-transform:uppercase}.dizzy-ticket-save{background:#fff;color:#050505}.dizzy-ticket-back{background:transparent;color:#fff}.dizzy-ticket-checkin{background:#222;margin:0 0 24px;padding:14px}@media(max-width:600px){body{padding:0}.dizzy-ticket-page{min-height:100vh;padding:42px 18px}h1{font-size:34px}.dizzy-ticket-actions>*{width:100%}}
            </style>
        </head>
        <body>
            <main class="dizzy-ticket-page">
                <?php if ($checkinResult !== '') : ?>
                    <div class="dizzy-ticket-checkin"><strong><?php echo esc_html($checkinResult === 'checked_in' ? __('Check-in completed.', 'dizzy-ticket-manager') : __('Ticket was already checked in or is invalid.', 'dizzy-ticket-manager')); ?></strong></div>
                <?php elseif (! empty($ticket['checked_in_at'])) : ?>
                    <div class="dizzy-ticket-checkin"><strong><?php esc_html_e('Checked in', 'dizzy-ticket-manager'); ?></strong></div>
                <?php endif; ?>
                <h1><?php esc_html_e('Your Ticket', 'dizzy-ticket-manager'); ?></h1>
                <h2><?php echo esc_html($eventName); ?></h2>
                <p class="dizzy-ticket-date"><?php echo esc_html($date); ?></p>
                <img id="dizzy-ticket-qr" class="dizzy-ticket-qr" src="<?php echo esc_url($qr); ?>" alt="<?php esc_attr_e('Ticket QR code', 'dizzy-ticket-manager'); ?>">
                <div class="dizzy-ticket-meta"><?php echo esc_html($type . ' · ' . $holder . ' · ' . $shortCode); ?></div>
                <p class="dizzy-ticket-note"><?php esc_html_e('Present this QR code at the entrance.', 'dizzy-ticket-manager'); ?></p>
                <div class="dizzy-ticket-actions">
                    <button type="button" class="dizzy-ticket-save" id="dizzy-save-ticket"><?php esc_html_e('Save Ticket', 'dizzy-ticket-manager'); ?></button>
                    <?php if ($checkinResult !== '') : ?>
                        <a class="dizzy-ticket-back" href="<?php echo esc_url(admin_url('admin.php?page=dizzy-ticket-checkin')); ?>"><?php esc_html_e('Return to Check-in', 'dizzy-ticket-manager'); ?></a>
                    <?php else : ?>
                        <button type="button" class="dizzy-ticket-back" onclick="history.length>1?history.back():window.close()"><?php esc_html_e('Back', 'dizzy-ticket-manager'); ?></button>
                    <?php endif; ?>
                </div>
            </main>
            <script>
            (()=>{const button=document.getElementById('dizzy-save-ticket'),img=document.getElementById('dizzy-ticket-qr');button.addEventListener('click',()=>{if(!img.complete)return;const canvas=document.createElement('canvas');canvas.width=1080;canvas.height=1600;const ctx=canvas.getContext('2d');ctx.fillStyle='#fff';ctx.fillRect(0,0,1080,1600);ctx.fillStyle='#111827';ctx.textAlign='center';ctx.font='700 54px sans-serif';wrap(<?php echo wp_json_encode(strtoupper($eventName)); ?>,540,150,900,68);ctx.font='36px sans-serif';ctx.fillText(<?php echo wp_json_encode($date); ?>,540,300);ctx.drawImage(img,260,390,560,560);ctx.font='700 38px sans-serif';ctx.fillText(<?php echo wp_json_encode($type); ?>,540,1040);ctx.font='32px sans-serif';ctx.fillText(<?php echo wp_json_encode($holder); ?>,540,1110);ctx.font='28px monospace';ctx.fillText(<?php echo wp_json_encode($shortCode); ?>,540,1180);ctx.font='28px sans-serif';ctx.fillText(<?php echo wp_json_encode(__('Present this QR code at the entrance.', 'dizzy-ticket-manager')); ?>,540,1335);const link=document.createElement('a');link.download=<?php echo wp_json_encode($filename); ?>;link.href=canvas.toDataURL('image/jpeg',.95);link.click();function wrap(text,x,y,max,line){let row='';for(const word of String(text).split(/\s+/)){const test=row?row+' '+word:word;if(ctx.measureText(test).width>max&&row){ctx.fillText(row,x,y);row=word;y+=line}else row=test}if(row)ctx.fillText(row,x,y)}})})();
            </script>
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
                esc_html__('Payment received. Your tickets are ready.', 'dizzy-ticket-manager') .
                '</strong></p><ul>';

            foreach ($this->repository->ticketsForOrder((int) $order['id']) as $ticket) {
                echo '<li><a href="' . esc_url($this->service->ticketUrl((string) $ticket['ticket_code'])) . '">' .
                    esc_html__('Open ticket', 'dizzy-ticket-manager') .
                    '</a></li>';
            }

            echo '</ul></div>';
            return;
        }

        if (in_array($status, ['failed', 'canceled', 'expired'], true)) {
            echo '<p class="dizzy-ticket-error">' .
                esc_html__('The payment was not completed.', 'dizzy-ticket-manager') .
                '</p>';
            return;
        }

        echo '<p>' . esc_html__('Your payment is being processed. Refresh this page shortly.', 'dizzy-ticket-manager') . '</p>';
    }
}
