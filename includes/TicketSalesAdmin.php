<?php

declare(strict_types=1);

namespace Dizzy\Tickets;

defined('ABSPATH') || exit;

final class TicketSalesAdmin
{
    private const MENU = 'dizzy-tickets';
    private const CHECKIN = 'dizzy-ticket-checkin';
    private const REPORTS = 'dizzy-ticket-reports';

    public function __construct(
        private TicketSalesRepository $repository,
        private EventGateway $events
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'settings']);
        add_action('admin_post_dizzy_ticket_checkin', [$this, 'checkIn']);
        add_action('admin_post_dizzy_ticket_undo_checkin', [$this, 'undoCheckIn']);
        add_action('admin_post_dizzy_ticket_report_csv', [$this, 'exportCsv']);
    }

    public function menu(): void
    {
        add_menu_page(__('Tickets', 'dizzy-ticket-manager'), __('Tickets', 'dizzy-ticket-manager'), 'manage_options', self::MENU, [$this, 'ordersPage'], 'dashicons-tickets-alt', 26);
        add_submenu_page(self::MENU, __('Tickets', 'dizzy-ticket-manager'), __('Tickets', 'dizzy-ticket-manager'), 'manage_options', self::MENU, [$this, 'ordersPage']);
        add_submenu_page(self::MENU, __('Check-in & Attendance', 'dizzy-ticket-manager'), __('Check-in', 'dizzy-ticket-manager'), 'manage_options', self::CHECKIN, [$this, 'checkinPage']);
        add_submenu_page(self::MENU, __('Ticket Reports', 'dizzy-ticket-manager'), __('Reports', 'dizzy-ticket-manager'), 'manage_options', self::REPORTS, [$this, 'reportsPage']);
        add_submenu_page(self::MENU, __('Payment Settings', 'dizzy-ticket-manager'), __('Payment Settings', 'dizzy-ticket-manager'), 'manage_options', 'dizzy-ticket-payment-settings', [$this, 'settingsPage']);
    }

    public function settings(): void
    {
        register_setting('dizzy_ticket_payment_settings', 'dizzy_ticket_mollie_api_key', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitizeApiKey'], 'default' => '']);
        register_setting('dizzy_ticket_payment_settings', 'dizzy_tm_ticket_hold_minutes', ['type' => 'integer', 'sanitize_callback' => static fn ($value): int => min(60, max(5, absint($value))), 'default' => 15]);
    }

    public function sanitizeApiKey(mixed $value): string
    {
        $value = trim(sanitize_text_field((string) $value));
        if ($value === '') return '';
        return preg_match('/^(test|live)_[A-Za-z0-9]+$/', $value) ? $value : (string) get_option('dizzy_ticket_mollie_api_key', '');
    }

    public function settingsPage(): void
    {
        $this->guard();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Payment Settings', 'dizzy-ticket-manager'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('dizzy_ticket_payment_settings'); ?>
                <table class="form-table">
                    <tr><th><label for="dizzy-mollie-key"><?php esc_html_e('Mollie API key', 'dizzy-ticket-manager'); ?></label></th><td><input id="dizzy-mollie-key" type="password" class="regular-text" name="dizzy_ticket_mollie_api_key" value="<?php echo esc_attr((string) get_option('dizzy_ticket_mollie_api_key', '')); ?>" autocomplete="new-password"><p class="description"><?php esc_html_e('Start with a Mollie test_ key. Replace it with a live_ key only after testing.', 'dizzy-ticket-manager'); ?></p></td></tr>
                    <tr><th><label for="dizzy-hold-minutes"><?php esc_html_e('Ticket hold time', 'dizzy-ticket-manager'); ?></label></th><td><input id="dizzy-hold-minutes" type="number" min="5" max="60" name="dizzy_tm_ticket_hold_minutes" value="<?php echo esc_attr((string) get_option('dizzy_tm_ticket_hold_minutes', 15)); ?>"> <?php esc_html_e('minutes', 'dizzy-ticket-manager'); ?></td></tr>
                    <tr><th><?php esc_html_e('Mollie webhook URL', 'dizzy-ticket-manager'); ?></th><td><code><?php echo esc_html(rest_url('dizzy-tickets/v1/mollie/webhook')); ?></code></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function ordersPage(): void
    {
        $this->guard();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Tickets', 'dizzy-ticket-manager'); ?></h1>
            <table class="widefat striped">
                <thead><tr><th>ID</th><th><?php esc_html_e('Customer', 'dizzy-ticket-manager'); ?></th><th><?php esc_html_e('Event', 'dizzy-ticket-manager'); ?></th><th><?php esc_html_e('Amount', 'dizzy-ticket-manager'); ?></th><th><?php esc_html_e('Status', 'dizzy-ticket-manager'); ?></th><th><?php esc_html_e('Created', 'dizzy-ticket-manager'); ?></th></tr></thead>
                <tbody><?php foreach ($this->repository->allOrders() as $order) : ?><tr><td>#<?php echo esc_html((string) $order['id']); ?></td><td><?php echo esc_html((string) $order['customer_name']); ?><br><?php echo esc_html((string) $order['customer_email']); ?></td><td><?php echo esc_html(get_the_title((int) $order['event_id'])); ?></td><td><?php echo esc_html((string) $order['currency'] . ' ' . (string) $order['total_amount']); ?></td><td><?php echo esc_html(ucfirst((string) $order['status'])); ?></td><td><?php echo esc_html((string) $order['created_at']); ?></td></tr><?php endforeach; ?></tbody>
            </table>
        </div>
        <?php
    }

    public function checkinPage(): void
    {
        $this->guard();
        $totals = $this->repository->attendanceTotals();
        ?>
        <style>#wpbody-content>.notice,#wpbody-content>.update-nag,#wpbody-content>.wrap>.notice{display:none!important}</style>
        <div class="wrap">
            <h1><?php esc_html_e('Check-in & Attendance', 'dizzy-ticket-manager'); ?></h1>
            <h2><?php esc_html_e('QR Scanner', 'dizzy-ticket-manager'); ?></h2>
            <p><?php esc_html_e('Allow camera access and point it at a ticket QR code.', 'dizzy-ticket-manager'); ?></p>
            <video id="dizzy-ticket-qr-video" style="width:100%;max-width:480px;background:#111" playsinline></video>
            <p id="dizzy-ticket-qr-message"></p>
            <p><input id="dizzy-ticket-qr-url" type="url" class="regular-text" placeholder="<?php esc_attr_e('Paste ticket URL', 'dizzy-ticket-manager'); ?>"> <button id="dizzy-ticket-open" class="button"><?php esc_html_e('Open ticket', 'dizzy-ticket-manager'); ?></button></p>
            <?php echo $this->cards([
                __('Sold tickets', 'dizzy-ticket-manager') => $totals['sold'],
                __('Expected attendees', 'dizzy-ticket-manager') => $totals['expected'],
                __('Checked-in tickets', 'dizzy-ticket-manager') => $totals['checked_in'],
                __('Guests attended', 'dizzy-ticket-manager') => $totals['attended'],
            ]); ?>
            <h2><?php esc_html_e('Manual Check-in', 'dizzy-ticket-manager'); ?></h2>
            <table class="widefat striped">
                <thead><tr><th><?php esc_html_e('Holder', 'dizzy-ticket-manager'); ?></th><th><?php esc_html_e('Ticket', 'dizzy-ticket-manager'); ?></th><th><?php esc_html_e('Event', 'dizzy-ticket-manager'); ?></th><th><?php esc_html_e('Date', 'dizzy-ticket-manager'); ?></th><th><?php esc_html_e('Checked in', 'dizzy-ticket-manager'); ?></th><th><?php esc_html_e('Action', 'dizzy-ticket-manager'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($this->repository->allTickets() as $ticket) : $code = (string) $ticket['ticket_code']; ?>
                    <tr>
                        <td><?php echo esc_html((string) $ticket['holder_name']); ?><br><?php echo esc_html((string) $ticket['holder_email']); ?></td>
                        <td><?php echo esc_html((string) ($ticket['ticket_name'] ?: strtoupper(substr($code, 0, 12)))); ?></td>
                        <td><?php echo esc_html((string) $ticket['post_title']); ?></td>
                        <td><?php echo esc_html((string) $ticket['start_datetime']); ?></td>
                        <td><?php echo esc_html((string) ($ticket['checked_in_at'] ?: '—')); ?></td>
                        <td><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="<?php echo empty($ticket['checked_in_at']) ? 'dizzy_ticket_checkin' : 'dizzy_ticket_undo_checkin'; ?>"><input type="hidden" name="ticket_code" value="<?php echo esc_attr($code); ?>"><?php wp_nonce_field('dizzy_ticket_checkin_' . $code); ?><button class="button"><?php echo esc_html(empty($ticket['checked_in_at']) ? __('Check in', 'dizzy-ticket-manager') : __('Undo', 'dizzy-ticket-manager')); ?></button></form></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <script>(()=>{const v=document.getElementById('dizzy-ticket-qr-video'),m=document.getElementById('dizzy-ticket-qr-message'),i=document.getElementById('dizzy-ticket-qr-url'),nonce=<?php echo wp_json_encode(wp_create_nonce('dizzy_ticket_qr_checkin')); ?>;const open=x=>{try{const u=new URL(x,location.origin);if(u.origin!==location.origin||!u.searchParams.has('dizzy_tm_paid_ticket'))throw 0;u.searchParams.set('checkin_nonce',nonce);location.assign(u.href);return true}catch(e){m.textContent='<?php echo esc_js(__('Invalid ticket URL.', 'dizzy-ticket-manager')); ?>';return false}};document.getElementById('dizzy-ticket-open').onclick=e=>{e.preventDefault();open(i.value)};if(!('BarcodeDetector'in window)||!navigator.mediaDevices?.getUserMedia){m.textContent='<?php echo esc_js(__('Camera QR scanning is not supported. Paste the ticket URL instead.', 'dizzy-ticket-manager')); ?>';return}const detector=new BarcodeDetector({formats:['qr_code']});navigator.mediaDevices.getUserMedia({video:{facingMode:'environment'}}).then(stream=>{v.srcObject=stream;v.play();const scan=async()=>{try{const codes=await detector.detect(v);if(codes[0]?.rawValue&&open(codes[0].rawValue)){stream.getTracks().forEach(track=>track.stop());return}}catch(e){}requestAnimationFrame(scan)};scan()}).catch(()=>m.textContent='<?php echo esc_js(__('Camera access was denied.', 'dizzy-ticket-manager')); ?>')})();</script>
        </div>
        <?php
    }

    public function reportsPage(): void
    {
        $this->guard();
        $summary = $this->repository->reportSummary();
        $export = wp_nonce_url(admin_url('admin-post.php?action=dizzy_ticket_report_csv'), 'dizzy_ticket_report_csv');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Ticket Reports', 'dizzy-ticket-manager'); ?></h1>
            <?php echo $this->cards([
                __('Tickets sold', 'dizzy-ticket-manager') => $summary['sold'],
                __('Tickets attended', 'dizzy-ticket-manager') => $summary['attended'],
                __('Revenue', 'dizzy-ticket-manager') => 'EUR ' . number_format_i18n($summary['revenue'], 2),
            ]); ?>
            <p><a class="button" href="<?php echo esc_url($export); ?>"><?php esc_html_e('Export CSV', 'dizzy-ticket-manager'); ?></a></p>
            <table class="widefat striped">
                <thead><tr><th><?php esc_html_e('Event', 'dizzy-ticket-manager'); ?></th><th><?php esc_html_e('Date', 'dizzy-ticket-manager'); ?></th><th><?php esc_html_e('Sold', 'dizzy-ticket-manager'); ?></th><th><?php esc_html_e('Attended', 'dizzy-ticket-manager'); ?></th><th><?php esc_html_e('Capacity', 'dizzy-ticket-manager'); ?></th><th><?php esc_html_e('Usage', 'dizzy-ticket-manager'); ?></th><th><?php esc_html_e('Revenue', 'dizzy-ticket-manager'); ?></th></tr></thead>
                <tbody><?php foreach ($this->repository->reportRows() as $row) : $capacity = (int) ($row['capacity'] ?? 0); $sold = (int) $row['sold']; ?><tr><td><?php echo esc_html((string) $row['post_title']); ?></td><td><?php echo esc_html((string) $row['start_datetime']); ?></td><td><?php echo esc_html((string) $sold); ?></td><td><?php echo esc_html((string) $row['attended']); ?></td><td><?php echo esc_html($capacity > 0 ? (string) $capacity : __('Unlimited', 'dizzy-ticket-manager')); ?></td><td><?php echo esc_html($capacity > 0 ? round($sold / $capacity * 100, 1) . '%' : '—'); ?></td><td>EUR <?php echo esc_html(number_format_i18n((float) $row['revenue'], 2)); ?></td></tr><?php endforeach; ?></tbody>
            </table>
        </div>
        <?php
    }

    public function checkIn(): void
    {
        $code = $this->authorizedCode();
        check_admin_referer('dizzy_ticket_checkin_' . $code);
        $this->repository->checkInTicket($code, get_current_user_id());
        $this->redirect(self::CHECKIN);
    }

    public function undoCheckIn(): void
    {
        $code = $this->authorizedCode();
        check_admin_referer('dizzy_ticket_checkin_' . $code);
        $this->repository->undoCheckInTicket($code);
        $this->redirect(self::CHECKIN);
    }

    public function exportCsv(): void
    {
        $this->guard();
        check_admin_referer('dizzy_ticket_report_csv');
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename=dizzy-ticket-report.csv');
        $output = fopen('php://output', 'wb');
        fputcsv($output, ['Event', 'Date', 'Sold', 'Attended', 'Capacity', 'Revenue EUR']);
        foreach ($this->repository->reportRows() as $row) {
            fputcsv($output, [$row['post_title'], $row['start_datetime'], $row['sold'], $row['attended'], $row['capacity'], $row['revenue']]);
        }
        fclose($output);
        exit;
    }

    private function cards(array $items): string
    {
        $html = '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:16px 0">';
        foreach ($items as $label => $value) {
            $html .= '<div style="background:#fff;border:1px solid #ccd0d4;padding:14px;min-width:150px"><strong style="font-size:22px;display:block">' . esc_html((string) $value) . '</strong>' . esc_html((string) $label) . '</div>';
        }
        return $html . '</div>';
    }

    private function authorizedCode(): string
    {
        $this->guard();
        $code = sanitize_text_field(wp_unslash((string) ($_POST['ticket_code'] ?? '')));
        if (! preg_match('/^[a-f0-9]{64}$/', $code)) wp_die(esc_html__('Invalid ticket.', 'dizzy-ticket-manager'));
        return $code;
    }

    private function redirect(string $page): never
    {
        wp_safe_redirect(admin_url('admin.php?page=' . $page));
        exit;
    }

    private function guard(): void
    {
        if (! current_user_can('manage_options')) wp_die(esc_html__('Unauthorized', 'dizzy-ticket-manager'));
    }
}
