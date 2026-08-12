<?php

declare(strict_types=1);

namespace Dizzy\Reservations;

use Throwable;

defined('ABSPATH') || exit;

final class TicketSalesAdmin
{
    private const MENU = 'dizzy-reservations';

    public function __construct(
        private TicketSalesRepository $repository,
        private EventGateway $events
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'settings']);
    }

    public function menu(): void
    {
        add_submenu_page(
            self::MENU,
            __('Ticket Orders', 'dizzy-reservations-manager'),
            __('Ticket Orders', 'dizzy-reservations-manager'),
            'manage_options',
            'dizzy-ticket-orders',
            [$this, 'ordersPage']
        );

        add_submenu_page(
            self::MENU,
            __('Payment Settings', 'dizzy-reservations-manager'),
            __('Payment Settings', 'dizzy-reservations-manager'),
            'manage_options',
            'dizzy-payment-settings',
            [$this, 'settingsPage']
        );
    }

    public function settings(): void
    {
        register_setting(
            'dizzy_payment_settings',
            'dizzy_mollie_api_key',
            ['type' => 'string', 'sanitize_callback' => [$this, 'sanitizeApiKey'], 'default' => '']
        );
        register_setting(
            'dizzy_payment_settings',
            'dizzy_ticket_hold_minutes',
            ['type' => 'integer', 'sanitize_callback' => static fn ($value): int => min(60, max(5, absint($value))), 'default' => 15]
        );
    }

    public function sanitizeApiKey(mixed $value): string
    {
        $value = trim(sanitize_text_field((string) $value));

        if ($value === '') {
            return '';
        }

        return preg_match('/^(test|live)_[A-Za-z0-9]+$/', $value) ? $value : (string) get_option('dizzy_mollie_api_key', '');
    }

    public function settingsPage(): void
    {
        $this->guard();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Payment Settings', 'dizzy-reservations-manager'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('dizzy_payment_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="dizzy-mollie-key"><?php esc_html_e('Mollie API key', 'dizzy-reservations-manager'); ?></label></th>
                        <td>
                            <input id="dizzy-mollie-key" type="password" class="regular-text" name="dizzy_mollie_api_key" value="<?php echo esc_attr((string) get_option('dizzy_mollie_api_key', '')); ?>" autocomplete="new-password">
                            <p class="description"><?php esc_html_e('Start with a Mollie test_ key. Replace it with a live_ key only after testing.', 'dizzy-reservations-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="dizzy-hold-minutes"><?php esc_html_e('Ticket hold time', 'dizzy-reservations-manager'); ?></label></th>
                        <td><input id="dizzy-hold-minutes" type="number" min="5" max="60" name="dizzy_ticket_hold_minutes" value="<?php echo esc_attr((string) get_option('dizzy_ticket_hold_minutes', 15)); ?>"> <?php esc_html_e('minutes', 'dizzy-reservations-manager'); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Mollie webhook URL', 'dizzy-reservations-manager'); ?></th>
                        <td><code><?php echo esc_html(rest_url('dizzy-reservations/v1/mollie/webhook')); ?></code></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function typesPage(): void
    {
        $this->guard();
        $editId = isset($_GET['edit_type']) ? absint($_GET['edit_type']) : 0;
        $edit = $editId > 0 ? $this->repository->findType($editId) : null;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Ticket Types', 'dizzy-reservations-manager'); ?></h1>
            <h2><?php echo esc_html($edit ? __('Edit Ticket Type', 'dizzy-reservations-manager') : __('Add Ticket Type', 'dizzy-reservations-manager')); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="dizzy_save_ticket_type">
                <input type="hidden" name="ticket_type_id" value="<?php echo esc_attr((string) ($edit['id'] ?? 0)); ?>">
                <?php wp_nonce_field('dizzy_save_ticket_type'); ?>
                <table class="form-table">
                    <tr><th><label for="dizzy-occurrence"><?php esc_html_e('Event', 'dizzy-reservations-manager'); ?></label></th><td>
                        <select id="dizzy-occurrence" name="occurrence" required>
                            <?php foreach ($this->events->allUpcoming() as $event) : ?>
                                <?php $value = (int) $event['event_id'] . ':' . (int) $event['id']; ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($value, (string) (($edit['event_id'] ?? 0) . ':' . ($edit['occurrence_id'] ?? 0))); ?>>
                                    <?php echo esc_html((string) $event['start_datetime'] . ' — ' . (string) $event['post_title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td></tr>
                    <tr><th><label for="dizzy-ticket-name"><?php esc_html_e('Name', 'dizzy-reservations-manager'); ?></label></th><td><input id="dizzy-ticket-name" class="regular-text" name="name" required value="<?php echo esc_attr((string) ($edit['name'] ?? 'Standard')); ?>"></td></tr>
                    <tr><th><label for="dizzy-ticket-price"><?php esc_html_e('Price', 'dizzy-reservations-manager'); ?></label></th><td>€ <input id="dizzy-ticket-price" name="price" inputmode="decimal" required value="<?php echo esc_attr((string) ($edit['price'] ?? '0.00')); ?>"></td></tr>
                    <tr><th><label for="dizzy-ticket-capacity"><?php esc_html_e('Capacity', 'dizzy-reservations-manager'); ?></label></th><td><input id="dizzy-ticket-capacity" type="number" min="0" name="capacity" value="<?php echo esc_attr((string) ($edit['capacity'] ?? 0)); ?>"><p class="description"><?php esc_html_e('Use 0 for unlimited.', 'dizzy-reservations-manager'); ?></p></td></tr>
                    <tr><th><?php esc_html_e('Active', 'dizzy-reservations-manager'); ?></th><td><label><input type="checkbox" name="active" value="1" <?php checked((int) ($edit['active'] ?? 1), 1); ?>> <?php esc_html_e('Available for sale', 'dizzy-reservations-manager'); ?></label></td></tr>
                </table>
                <?php submit_button($edit ? __('Update Ticket Type', 'dizzy-reservations-manager') : __('Add Ticket Type', 'dizzy-reservations-manager')); ?>
            </form>

            <h2><?php esc_html_e('Existing Ticket Types', 'dizzy-reservations-manager'); ?></h2>
            <table class="widefat striped">
                <thead><tr><th><?php esc_html_e('Event', 'dizzy-reservations-manager'); ?></th><th><?php esc_html_e('Name', 'dizzy-reservations-manager'); ?></th><th><?php esc_html_e('Price', 'dizzy-reservations-manager'); ?></th><th><?php esc_html_e('Capacity', 'dizzy-reservations-manager'); ?></th><th><?php esc_html_e('Status', 'dizzy-reservations-manager'); ?></th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($this->repository->allTypes() as $type) : ?>
                        <tr>
                            <td><?php echo esc_html(get_the_title((int) $type['event_id'])); ?></td>
                            <td><?php echo esc_html((string) $type['name']); ?></td>
                            <td>€ <?php echo esc_html((string) $type['price']); ?></td>
                            <td><?php echo esc_html((string) ($type['capacity'] ?: __('Unlimited', 'dizzy-reservations-manager'))); ?></td>
                            <td><?php echo esc_html((int) $type['active'] === 1 ? __('Active', 'dizzy-reservations-manager') : __('Inactive', 'dizzy-reservations-manager')); ?></td>
                            <td><a href="<?php echo esc_url(admin_url('admin.php?page=dizzy-ticket-types&edit_type=' . (int) $type['id'])); ?>"><?php esc_html_e('Edit', 'dizzy-reservations-manager'); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function saveType(): void
    {
        $this->guard();
        check_admin_referer('dizzy_save_ticket_type');

        $parts = explode(':', sanitize_text_field(wp_unslash((string) ($_POST['occurrence'] ?? ''))));

        if (count($parts) !== 2) {
            wp_die(esc_html__('Invalid event selection.', 'dizzy-reservations-manager'));
        }

        $eventId = absint($parts[0]);
        $occurrenceId = absint($parts[1]);

        if ($this->events->occurrence($eventId, $occurrenceId) === null) {
            wp_die(esc_html__('Selected event is unavailable.', 'dizzy-reservations-manager'));
        }

        try {
            $this->repository->saveType([
                'id' => absint($_POST['ticket_type_id'] ?? 0),
                'event_id' => $eventId,
                'occurrence_id' => $occurrenceId,
                'name' => wp_unslash((string) ($_POST['name'] ?? '')),
                'price' => str_replace(',', '.', wp_unslash((string) ($_POST['price'] ?? '0'))),
                'capacity' => absint($_POST['capacity'] ?? 0),
                'active' => isset($_POST['active']),
            ]);
        } catch (Throwable $exception) {
            wp_die(esc_html($exception->getMessage()));
        }

        wp_safe_redirect(admin_url('admin.php?page=dizzy-ticket-types'));
        exit;
    }

    public function ordersPage(): void
    {
        $this->guard();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Ticket Orders', 'dizzy-reservations-manager'); ?></h1>
            <table class="widefat striped">
                <thead><tr><th>ID</th><th><?php esc_html_e('Customer', 'dizzy-reservations-manager'); ?></th><th><?php esc_html_e('Event', 'dizzy-reservations-manager'); ?></th><th><?php esc_html_e('Amount', 'dizzy-reservations-manager'); ?></th><th><?php esc_html_e('Status', 'dizzy-reservations-manager'); ?></th><th><?php esc_html_e('Created', 'dizzy-reservations-manager'); ?></th></tr></thead>
                <tbody>
                    <?php foreach ($this->repository->allOrders() as $order) : ?>
                        <tr>
                            <td>#<?php echo esc_html((string) $order['id']); ?></td>
                            <td><?php echo esc_html((string) $order['customer_name']); ?><br><?php echo esc_html((string) $order['customer_email']); ?></td>
                            <td><?php echo esc_html(get_the_title((int) $order['event_id'])); ?></td>
                            <td><?php echo esc_html((string) $order['currency'] . ' ' . (string) $order['total_amount']); ?></td>
                            <td><?php echo esc_html(ucfirst((string) $order['status'])); ?></td>
                            <td><?php echo esc_html((string) $order['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function guard(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'dizzy-reservations-manager'));
        }
    }
}
