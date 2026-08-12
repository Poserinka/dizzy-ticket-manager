<?php

declare(strict_types=1);

namespace Dizzy\Reservations;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

defined('ABSPATH') || exit;

final class FrontendController
{
    public function __construct(
        private EventGateway $events,
        private ReservationService $service
    ) {
    }

    public function register(): void
    {
        add_shortcode('dizzy_reservation_form', [$this, 'shortcode']);
        add_action('template_redirect', [$this, 'submit']);
    }

    public function shortcode(array $atts = []): string
    {
        $atts = shortcode_atts(['event_id' => get_the_ID()], $atts);
        $eventId = absint($atts['event_id']);
        $rows = $this->events->upcoming($eventId);

        if ($rows === []) {
            return '<p>' . esc_html__('No reservable event dates are available.', 'dizzy-reservations-manager') . '</p>';
        }

        ob_start();

        if (isset($_GET['reservation'])) {
            $result = sanitize_key(wp_unslash((string) $_GET['reservation']));
            echo '<p>' . esc_html(
                $result === 'success'
                    ? __('Reservation received.', 'dizzy-reservations-manager')
                    : __('Reservation could not be completed.', 'dizzy-reservations-manager')
            ) . '</p>';
        }
        ?>
        <form method="post" class="dizzy-reservation-form">
            <?php wp_nonce_field('dizzy_reservation_submit', 'dizzy_reservation_nonce'); ?>
            <input type="hidden" name="event_id" value="<?php echo esc_attr((string) $eventId); ?>">

            <p>
                <label>
                    <?php esc_html_e('Events', 'dizzy-reservations-manager'); ?><br>
                    <select name="occurrence_id" required>
                        <?php foreach ($rows as $row) : ?>
                            <option value="<?php echo esc_attr((string) $row['id']); ?>">
                                <?php echo esc_html($this->eventOptionLabel($row)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </p>

            <p><label><?php esc_html_e('Name', 'dizzy-reservations-manager'); ?><br><input name="name" required></label></p>
            <p><label><?php esc_html_e('Email', 'dizzy-reservations-manager'); ?><br><input type="email" name="email" required></label></p>
            <p><label><?php esc_html_e('Phone', 'dizzy-reservations-manager'); ?><br><input name="phone"></label></p>
            <p><label><?php esc_html_e('Guests', 'dizzy-reservations-manager'); ?><br><input type="number" name="guests" min="1" max="100" value="1" required></label></p>
            <button type="submit" name="dizzy_reservation_submit" value="1"><?php esc_html_e('Reserve', 'dizzy-reservations-manager'); ?></button>
        </form>
        <?php

        return (string) ob_get_clean();
    }

    public function submit(): void
    {
        if (
            ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
            || ! isset($_POST['dizzy_reservation_submit'])
        ) {
            return;
        }

        $nonce = sanitize_text_field(
            wp_unslash((string) ($_POST['dizzy_reservation_nonce'] ?? ''))
        );

        if (! wp_verify_nonce($nonce, 'dizzy_reservation_submit')) {
            return;
        }

        try {
            $this->service->create(wp_unslash($_POST));
            $result = 'success';
        } catch (Throwable $exception) {
            error_log('Dizzy reservation failed: ' . $exception->getMessage());
            $result = 'error';
        }

        wp_safe_redirect(
            add_query_arg('reservation', $result, wp_get_referer() ?: home_url('/'))
        );
        exit;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function eventOptionLabel(array $row): string
    {
        try {
            $timezone = new DateTimeZone(
                (string) ($row['timezone'] ?: wp_timezone_string())
            );
        } catch (Throwable) {
            $timezone = wp_timezone();
        }

        try {
            $date = new DateTimeImmutable((string) $row['start_datetime'], $timezone);
            $dateLabel = wp_date('d.F Y - H.i', $date->getTimestamp(), $timezone);
        } catch (Throwable) {
            $dateLabel = (string) ($row['start_datetime'] ?? '');
        }

        $title = trim((string) ($row['post_title'] ?? ''));

        return $title === '' ? $dateLabel : $dateLabel . ' - ' . $title;
    }
}
