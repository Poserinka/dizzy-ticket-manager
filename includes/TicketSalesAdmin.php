<?php

declare(strict_types=1);

namespace Dizzy\Tickets;

defined('ABSPATH') || exit;

final class TicketSalesAdmin
{
    private const MENU = 'dizzy-tickets';
    private const ORDERS = 'dizzy-ticket-orders';
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
        add_menu_page(__('Tickets', 'dizzy-ticket-manager'), __('Tickets', 'dizzy-ticket-manager'), ControllerRole::TICKETS_CAP, self::MENU, [$this, 'ticketsPage'], 'dashicons-tickets-alt', 26);
        add_submenu_page(self::MENU, __('Tickets', 'dizzy-ticket-manager'), __('Tickets', 'dizzy-ticket-manager'), ControllerRole::TICKETS_CAP, self::MENU, [$this, 'ticketsPage']);
        add_submenu_page(self::MENU, __('Ticket Orders', 'dizzy-ticket-manager'), __('Ticket Orders', 'dizzy-ticket-manager'), ControllerRole::TICKETS_CAP, self::ORDERS, [$this, 'ordersPage']);
        add_submenu_page(self::MENU, __('Check-in & Attendance', 'dizzy-ticket-manager'), __('Check-in', 'dizzy-ticket-manager'), ControllerRole::TICKETS_CAP, self::CHECKIN, [$this, 'checkinPage']);
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

    public function ticketsPage(): void
    {
        $this->guard(ControllerRole::TICKETS_CAP);
        $tickets = $this->repository->allTickets();
        $calendarRows = array_map(static fn (array $ticket): array => [
            'date' => substr((string) ($ticket['start_datetime'] ?? ''), 0, 10),
            'checked' => ! empty($ticket['checked_in_at']),
        ], $tickets);
        ?>
        <div class="wrap dizzy-ticket-list-admin">
            <h1><?php esc_html_e('Tickets', 'dizzy-ticket-manager'); ?></h1>
            <div class="dizzy-ticket-list-workspace">
                <section class="dizzy-ticket-list-panel">
                    <div class="dizzy-ticket-list-heading">
                        <div>
                            <h2 id="dizzy-tickets-list-title"><?php esc_html_e('All Tickets', 'dizzy-ticket-manager'); ?></h2>
                            <div class="dizzy-ticket-list-summary">
                                <span><?php esc_html_e('Total tickets:', 'dizzy-ticket-manager'); ?> <strong id="dizzy-tickets-total"><?php echo esc_html((string) count($tickets)); ?></strong></span>
                                <span><?php esc_html_e('Checked-in:', 'dizzy-ticket-manager'); ?> <strong id="dizzy-tickets-secondary"><?php echo esc_html((string) count(array_filter($calendarRows, static fn (array $row): bool => $row['checked']))); ?></strong></span>
                            </div>
                        </div>
                        <button type="button" class="button-link" id="dizzy-tickets-show-all"><?php esc_html_e('Show all', 'dizzy-ticket-manager'); ?></button>
                    </div>
                    <div class="dizzy-ticket-list-table-wrap">
                        <table class="widefat striped">
                            <thead><tr><th><?php esc_html_e('Ticket', 'dizzy-ticket-manager'); ?></th><th><?php esc_html_e('Holder', 'dizzy-ticket-manager'); ?></th><th><?php esc_html_e('Type', 'dizzy-ticket-manager'); ?></th><th><?php esc_html_e('Event', 'dizzy-ticket-manager'); ?></th><th><?php esc_html_e('Event date', 'dizzy-ticket-manager'); ?></th><th><?php esc_html_e('Status', 'dizzy-ticket-manager'); ?></th><th><?php esc_html_e('Created', 'dizzy-ticket-manager'); ?></th></tr></thead>
                            <tbody>
                            <?php foreach ($tickets as $ticket) : $code = (string) $ticket['ticket_code']; $date = substr((string) ($ticket['start_datetime'] ?? ''), 0, 10); ?>
                                <tr data-tickets-date="<?php echo esc_attr($date); ?>">
                                    <td><code><?php echo esc_html(strtoupper(substr($code, 0, 12))); ?></code></td>
                                    <td><?php echo esc_html((string) $ticket['holder_name']); ?><br><?php echo esc_html((string) $ticket['holder_email']); ?></td>
                                    <td><?php echo esc_html((string) ($ticket['ticket_name'] ?: '—')); ?></td>
                                    <td><?php echo esc_html((string) $ticket['post_title']); ?></td>
                                    <td><?php echo esc_html((string) $ticket['start_datetime']); ?></td>
                                    <td><?php echo esc_html(empty($ticket['checked_in_at']) ? __('Valid', 'dizzy-ticket-manager') : __('Checked in', 'dizzy-ticket-manager')); ?></td>
                                    <td><?php echo esc_html((string) $ticket['created_at']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr id="dizzy-tickets-empty" hidden><td colspan="7"><?php esc_html_e('No tickets for this date.', 'dizzy-ticket-manager'); ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>
                <aside class="dizzy-ticket-list-calendar-column"><?php $this->listCalendar($calendarRows, 'tickets'); ?></aside>
            </div>
        </div>
        <?php
    }

    public function ordersPage(): void
    {
        $this->guard(ControllerRole::TICKETS_CAP);
        $orders = $this->repository->allOrders();
        $calendarRows = array_map(static fn (array $order): array => [
            'date' => substr((string) ($order['start_datetime'] ?? ''), 0, 10),
            'paid' => (string) ($order['status'] ?? '') === 'paid',
            'amount' => (float) ($order['total_amount'] ?? 0),
        ], $orders);
        ?>
        <div class="wrap dizzy-ticket-list-admin">
            <h1><?php esc_html_e('Ticket Orders', 'dizzy-ticket-manager'); ?></h1>
            <div class="dizzy-ticket-list-workspace">
                <section class="dizzy-ticket-list-panel">
                    <div class="dizzy-ticket-list-heading">
                        <div>
                            <h2 id="dizzy-orders-list-title"><?php esc_html_e('All Ticket Orders', 'dizzy-ticket-manager'); ?></h2>
                            <div class="dizzy-ticket-list-summary">
                                <span><?php esc_html_e('Total orders:', 'dizzy-ticket-manager'); ?> <strong id="dizzy-orders-total"><?php echo esc_html((string) count($orders)); ?></strong></span>
                                <span><?php esc_html_e('Paid orders:', 'dizzy-ticket-manager'); ?> <strong id="dizzy-orders-secondary"><?php echo esc_html((string) count(array_filter($calendarRows, static fn (array $row): bool => $row['paid']))); ?></strong></span>
                                <span><?php esc_html_e('Revenue:', 'dizzy-ticket-manager'); ?> EUR <strong id="dizzy-orders-revenue"><?php echo esc_html(number_format_i18n(array_sum(array_column($calendarRows, 'amount')), 2)); ?></strong></span>
                            </div>
                        </div>
                        <button type="button" class="button-link" id="dizzy-orders-show-all"><?php esc_html_e('Show all', 'dizzy-ticket-manager'); ?></button>
                    </div>
                    <div class="dizzy-ticket-list-table-wrap">
                        <table class="widefat striped">
                            <thead><tr><th>ID</th><th><?php esc_html_e('Customer', 'dizzy-ticket-manager'); ?></th><th><?php esc_html_e('Event', 'dizzy-ticket-manager'); ?></th><th><?php esc_html_e('Event date', 'dizzy-ticket-manager'); ?></th><th><?php esc_html_e('Amount', 'dizzy-ticket-manager'); ?></th><th><?php esc_html_e('Status', 'dizzy-ticket-manager'); ?></th><th><?php esc_html_e('Created', 'dizzy-ticket-manager'); ?></th></tr></thead>
                            <tbody>
                            <?php foreach ($orders as $order) : $date = substr((string) ($order['start_datetime'] ?? ''), 0, 10); ?>
                                <tr data-orders-date="<?php echo esc_attr($date); ?>">
                                    <td>#<?php echo esc_html((string) $order['id']); ?></td>
                                    <td><?php echo esc_html((string) $order['customer_name']); ?><br><?php echo esc_html((string) $order['customer_email']); ?></td>
                                    <td><?php echo esc_html((string) ($order['post_title'] ?: get_the_title((int) $order['event_id']))); ?></td>
                                    <td><?php echo esc_html((string) ($order['start_datetime'] ?: '—')); ?></td>
                                    <td><?php echo esc_html((string) $order['currency'] . ' ' . (string) $order['total_amount']); ?></td>
                                    <td><?php echo esc_html(ucfirst((string) $order['status'])); ?></td>
                                    <td><?php echo esc_html((string) $order['created_at']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr id="dizzy-orders-empty" hidden><td colspan="7"><?php esc_html_e('No ticket orders for this date.', 'dizzy-ticket-manager'); ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>
                <aside class="dizzy-ticket-list-calendar-column"><?php $this->listCalendar($calendarRows, 'orders'); ?></aside>
            </div>
        </div>
        <?php
    }

    private function listCalendar(array $rows, string $kind): void
    {
        $isTickets = $kind === 'tickets';
        $itemLabel = $isTickets ? __('tickets', 'dizzy-ticket-manager') : __('orders', 'dizzy-ticket-manager');
        $allLabel = $isTickets ? __('All Tickets', 'dizzy-ticket-manager') : __('All Ticket Orders', 'dizzy-ticket-manager');
        ?>
        <style>
            .dizzy-ticket-list-workspace{display:grid;grid-template-columns:minmax(0,1.7fr) minmax(460px,.9fr);gap:24px;align-items:start;margin-top:14px}
            .dizzy-ticket-list-panel,.dizzy-list-calendar-surface{background:#fff;border:1px solid #c3c4c7}
            .dizzy-ticket-list-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:12px;border-bottom:1px solid #c3c4c7}
            .dizzy-ticket-list-heading h2{margin:0 0 8px;font-size:14px}.dizzy-ticket-list-summary{display:flex;align-items:center;gap:18px;flex-wrap:wrap}.dizzy-ticket-list-summary span{color:#50575e}.dizzy-ticket-list-summary strong{color:#1d2327}
            .dizzy-ticket-list-table-wrap{overflow-x:auto}.dizzy-ticket-list-table-wrap .widefat{border:0}.dizzy-ticket-list-table-wrap .widefat tbody td{vertical-align:middle}.dizzy-ticket-list-table-wrap tr.is-calendar-hidden{display:none}
            .dizzy-list-calendar-toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px}.dizzy-list-calendar-nav{display:flex;align-items:center;gap:8px}
            .dizzy-list-calendar-month{min-width:150px;text-align:center;font-size:15px;font-weight:600;text-transform:capitalize}
            .dizzy-list-calendar-weekdays,.dizzy-list-calendar-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr))}
            .dizzy-list-calendar-weekdays div{padding:10px 4px;text-align:center;font-size:12px;font-weight:600;border-bottom:1px solid #dcdcde}
            .dizzy-list-calendar-day{position:relative;min-height:72px;padding:7px;border:0;border-right:1px solid #e2e4e7;border-bottom:1px solid #e2e4e7;background:#fff;color:#1d2327;text-align:left;cursor:pointer}
            .dizzy-list-calendar-day:nth-child(7n){border-right:0}.dizzy-list-calendar-day:hover{background:#f6f7f7}.dizzy-list-calendar-day.is-selected{background:#eef5ff;box-shadow:inset 0 0 0 2px #2271b1}
            .dizzy-list-calendar-day.is-other{color:#8c8f94;background:#fafafa}.dizzy-list-calendar-day-number{font-weight:600}
            .dizzy-list-calendar-count{display:block;width:max-content;max-width:100%;margin-top:15px;padding:3px 6px;border-radius:10px;background:#e7f3ff;font-size:10px;white-space:nowrap}.dizzy-list-calendar-count.is-busy{background:#fff0d5}
            @media(max-width:1300px){.dizzy-ticket-list-workspace{grid-template-columns:minmax(0,1.35fr) minmax(400px,.9fr)}.dizzy-list-calendar-day{min-height:64px}}
            @media(max-width:1050px){.dizzy-ticket-list-workspace{display:flex;flex-direction:column-reverse}.dizzy-ticket-list-panel,.dizzy-ticket-list-calendar-column{width:100%}}
            @media(max-width:600px){.dizzy-list-calendar-day{min-height:52px;padding:4px}.dizzy-list-calendar-count{overflow:hidden;margin-top:5px;padding:0;width:8px;height:8px;text-indent:-9999px}.dizzy-list-calendar-toolbar{align-items:flex-start;flex-direction:column}}
        </style>
        <div class="dizzy-list-calendar-toolbar">
            <div class="dizzy-list-calendar-nav">
                <button type="button" class="button" id="dizzy-<?php echo esc_attr($kind); ?>-calendar-prev" aria-label="<?php esc_attr_e('Previous month', 'dizzy-ticket-manager'); ?>">‹</button>
                <div class="dizzy-list-calendar-month" id="dizzy-<?php echo esc_attr($kind); ?>-calendar-month"></div>
                <button type="button" class="button" id="dizzy-<?php echo esc_attr($kind); ?>-calendar-next" aria-label="<?php esc_attr_e('Next month', 'dizzy-ticket-manager'); ?>">›</button>
            </div>
            <button type="button" class="button" id="dizzy-<?php echo esc_attr($kind); ?>-calendar-today"><?php esc_html_e('Today', 'dizzy-ticket-manager'); ?></button>
        </div>
        <div class="dizzy-list-calendar-surface">
            <div class="dizzy-list-calendar-weekdays"><?php foreach ([__('Mon', 'dizzy-ticket-manager'), __('Tue', 'dizzy-ticket-manager'), __('Wed', 'dizzy-ticket-manager'), __('Thu', 'dizzy-ticket-manager'), __('Fri', 'dizzy-ticket-manager'), __('Sat', 'dizzy-ticket-manager'), __('Sun', 'dizzy-ticket-manager')] as $day) : ?><div><?php echo esc_html($day); ?></div><?php endforeach; ?></div>
            <div class="dizzy-list-calendar-grid" id="dizzy-<?php echo esc_attr($kind); ?>-calendar-grid"></div>
        </div>
        <script>
        (() => {
            const kind = <?php echo wp_json_encode($kind); ?>;
            const rows = <?php echo wp_json_encode($rows); ?>;
            const grid = document.getElementById('dizzy-' + kind + '-calendar-grid');
            if (!grid) return;
            const monthLabel = document.getElementById('dizzy-' + kind + '-calendar-month');
            const listTitle = document.getElementById('dizzy-' + kind + '-list-title');
            const showAll = document.getElementById('dizzy-' + kind + '-show-all');
            const totalEl = document.getElementById('dizzy-' + kind + '-total');
            const secondaryEl = document.getElementById('dizzy-' + kind + '-secondary');
            const revenueEl = document.getElementById('dizzy-' + kind + '-revenue');
            const tableRows = Array.from(document.querySelectorAll('[data-' + kind + '-date]'));
            const emptyRow = document.getElementById('dizzy-' + kind + '-empty');
            const locale = <?php echo wp_json_encode(str_replace('_', '-', determine_locale())); ?>;
            const allLabel = <?php echo wp_json_encode($allLabel); ?>;
            const itemLabel = <?php echo wp_json_encode($itemLabel); ?>;
            const grouped = {};
            rows.forEach(row => { if (/^\d{4}-\d{2}-\d{2}$/.test(row.date)) (grouped[row.date] ||= []).push(row); });
            const now = new Date();
            let view = new Date(now.getFullYear(), now.getMonth(), 1);
            let selected = '';

            function localKey(date) {
                return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
            }

            function filterList() {
                let visible = 0;
                tableRows.forEach(row => {
                    const show = !selected || row.getAttribute('data-' + kind + '-date') === selected;
                    row.classList.toggle('is-calendar-hidden', !show);
                    if (show) visible++;
                });
                emptyRow.hidden = !selected || visible > 0;
                const filtered = selected ? rows.filter(row => row.date === selected) : rows;
                totalEl.textContent = String(filtered.length);
                secondaryEl.textContent = String(filtered.filter(row => kind === 'tickets' ? row.checked : row.paid).length);
                if (revenueEl) revenueEl.textContent = new Intl.NumberFormat(locale, {minimumFractionDigits:2, maximumFractionDigits:2}).format(filtered.reduce((sum, row) => sum + Number(row.amount || 0), 0));
                showAll.hidden = !selected;
                listTitle.textContent = selected ? new Intl.DateTimeFormat(locale, {weekday:'long',day:'numeric',month:'long',year:'numeric'}).format(new Date(selected + 'T12:00:00')) : allLabel;
            }

            function renderCalendar() {
                monthLabel.textContent = new Intl.DateTimeFormat(locale, {month:'long',year:'numeric'}).format(view);
                grid.innerHTML = '';
                const first = new Date(view.getFullYear(), view.getMonth(), 1);
                const offset = (first.getDay() + 6) % 7;
                const start = new Date(view.getFullYear(), view.getMonth(), 1 - offset);
                for (let index = 0; index < 42; index++) {
                    const date = new Date(start); date.setDate(start.getDate() + index);
                    const key = localKey(date); const entries = grouped[key] || [];
                    const button = document.createElement('button'); button.type = 'button';
                    button.className = 'dizzy-list-calendar-day' + (date.getMonth() !== view.getMonth() ? ' is-other' : '') + (key === selected ? ' is-selected' : '');
                    button.setAttribute('aria-label', date.toDateString() + (entries.length ? ', ' + entries.length + ' ' + itemLabel : ''));
                    const number = document.createElement('span'); number.className = 'dizzy-list-calendar-day-number'; number.textContent = String(date.getDate()); button.appendChild(number);
                    if (entries.length) {
                        const count = document.createElement('span'); count.className = 'dizzy-list-calendar-count' + (entries.length >= 10 ? ' is-busy' : '');
                        count.textContent = entries.length + ' ' + itemLabel; button.appendChild(count);
                    }
                    button.addEventListener('click', () => { selected = key; if (date.getMonth() !== view.getMonth()) view = new Date(date.getFullYear(), date.getMonth(), 1); renderCalendar(); filterList(); });
                    grid.appendChild(button);
                }
            }

            showAll.addEventListener('click', () => { selected = ''; renderCalendar(); filterList(); });
            document.getElementById('dizzy-' + kind + '-calendar-prev').addEventListener('click', () => { view = new Date(view.getFullYear(), view.getMonth() - 1, 1); renderCalendar(); });
            document.getElementById('dizzy-' + kind + '-calendar-next').addEventListener('click', () => { view = new Date(view.getFullYear(), view.getMonth() + 1, 1); renderCalendar(); });
            document.getElementById('dizzy-' + kind + '-calendar-today').addEventListener('click', () => { const today = new Date(); view = new Date(today.getFullYear(), today.getMonth(), 1); selected = localKey(today); renderCalendar(); filterList(); });
            renderCalendar(); filterList();
        })();
        </script>
        <?php
    }

    public function checkinPage(): void
    {
        $this->guard(ControllerRole::TICKETS_CAP);
        $today = current_time('Y-m-d');
        $totals = $this->repository->attendanceTotals($today);
        $manualTickets = $this->repository->allTickets($today);
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
            <h2><?php esc_html_e('Manual Check-in', 'dizzy-ticket-manager'); ?> — <?php echo esc_html(wp_date(get_option('date_format'), strtotime($today), wp_timezone())); ?></h2>
            <table class="widefat striped">
                <thead><tr><th><?php esc_html_e('Holder', 'dizzy-ticket-manager'); ?></th><th><?php esc_html_e('Ticket', 'dizzy-ticket-manager'); ?></th><th><?php esc_html_e('Event', 'dizzy-ticket-manager'); ?></th><th><?php esc_html_e('Date', 'dizzy-ticket-manager'); ?></th><th><?php esc_html_e('Checked in', 'dizzy-ticket-manager'); ?></th><th><?php esc_html_e('Action', 'dizzy-ticket-manager'); ?></th></tr></thead>
                <tbody>
                <?php if ($manualTickets === []) : ?>
                    <tr><td colspan="6"><?php esc_html_e('No tickets are available for today.', 'dizzy-ticket-manager'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($manualTickets as $ticket) : $code = (string) $ticket['ticket_code']; ?>
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
        $rows = $this->repository->reportRows();
        $summary = $this->repository->reportSummary();
        $calendarRows = array_map(static fn (array $row): array => [
            'date' => substr((string) ($row['start_datetime'] ?? ''), 0, 10),
            'sold' => (int) ($row['sold'] ?? 0),
            'attended' => (int) ($row['attended'] ?? 0),
            'revenue' => (float) ($row['revenue'] ?? 0),
        ], $rows);
        $export = wp_nonce_url(admin_url('admin-post.php?action=dizzy_ticket_report_csv'), 'dizzy_ticket_report_csv');
        ?>
        <div class="wrap dizzy-ticket-reports-admin">
            <h1><?php esc_html_e('Ticket Reports', 'dizzy-ticket-manager'); ?></h1>
            <div class="dizzy-ticket-reports-workspace">
                <section class="dizzy-ticket-report-list">
                    <div class="dizzy-ticket-report-heading">
                        <div>
                            <h2 id="dizzy-ticket-report-title"><?php esc_html_e('All Ticket Reports', 'dizzy-ticket-manager'); ?></h2>
                            <div class="dizzy-ticket-report-cards">
                                <div><strong id="dizzy-report-sold"><?php echo esc_html((string) $summary['sold']); ?></strong><?php esc_html_e('Tickets sold', 'dizzy-ticket-manager'); ?></div>
                                <div><strong id="dizzy-report-attended"><?php echo esc_html((string) $summary['attended']); ?></strong><?php esc_html_e('Tickets attended', 'dizzy-ticket-manager'); ?></div>
                                <div><strong id="dizzy-report-revenue"><?php echo esc_html(number_format_i18n((float) $summary['revenue'], 2)); ?></strong><?php esc_html_e('Revenue (EUR)', 'dizzy-ticket-manager'); ?></div>
                            </div>
                        </div>
                        <button type="button" class="button-link" id="dizzy-ticket-report-show-all"><?php esc_html_e('Show all', 'dizzy-ticket-manager'); ?></button>
                    </div>
                    <div class="dizzy-ticket-report-actions"><a class="button" href="<?php echo esc_url($export); ?>"><?php esc_html_e('Export CSV', 'dizzy-ticket-manager'); ?></a></div>
                    <div class="dizzy-ticket-report-table-wrap">
                        <table class="widefat striped">
                            <thead><tr><th><?php esc_html_e('Event', 'dizzy-ticket-manager'); ?></th><th><?php esc_html_e('Date', 'dizzy-ticket-manager'); ?></th><th><?php esc_html_e('Sold', 'dizzy-ticket-manager'); ?></th><th><?php esc_html_e('Attended', 'dizzy-ticket-manager'); ?></th><th><?php esc_html_e('Capacity', 'dizzy-ticket-manager'); ?></th><th><?php esc_html_e('Usage', 'dizzy-ticket-manager'); ?></th><th><?php esc_html_e('Revenue', 'dizzy-ticket-manager'); ?></th></tr></thead>
                            <tbody>
                            <?php foreach ($rows as $row) : $capacity = (int) ($row['capacity'] ?? 0); $sold = (int) $row['sold']; $date = substr((string) ($row['start_datetime'] ?? ''), 0, 10); ?>
                                <tr data-ticket-report-date="<?php echo esc_attr($date); ?>">
                                    <td><?php echo esc_html((string) $row['post_title']); ?></td>
                                    <td><?php echo esc_html((string) $row['start_datetime']); ?></td>
                                    <td><?php echo esc_html((string) $sold); ?></td>
                                    <td><?php echo esc_html((string) $row['attended']); ?></td>
                                    <td><?php echo esc_html($capacity > 0 ? (string) $capacity : __('Unlimited', 'dizzy-ticket-manager')); ?></td>
                                    <td><?php echo esc_html($capacity > 0 ? round($sold / $capacity * 100, 1) . '%' : '—'); ?></td>
                                    <td>EUR <?php echo esc_html(number_format_i18n((float) $row['revenue'], 2)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr id="dizzy-ticket-report-empty" hidden><td colspan="7"><?php esc_html_e('No ticket reports for this date.', 'dizzy-ticket-manager'); ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>
                <aside class="dizzy-ticket-report-calendar-column">
                    <?php $this->reportsCalendar($calendarRows); ?>
                </aside>
            </div>
        </div>
        <?php
    }

    private function reportsCalendar(array $rows): void
    {
        ?>
        <style>
            .dizzy-ticket-reports-workspace{display:grid;grid-template-columns:minmax(0,1.7fr) minmax(460px,.9fr);gap:24px;align-items:start;margin-top:14px}
            .dizzy-ticket-report-list,.dizzy-ticket-calendar-surface{background:#fff;border:1px solid #c3c4c7}
            .dizzy-ticket-report-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:12px;border-bottom:1px solid #c3c4c7}
            .dizzy-ticket-report-heading h2{margin:0 0 12px;font-size:14px}
            .dizzy-ticket-report-cards{display:flex;gap:12px;flex-wrap:wrap}
            .dizzy-ticket-report-cards>div{background:#fff;border:1px solid #ccd0d4;padding:12px 14px;min-width:150px}
            .dizzy-ticket-report-cards strong{display:block;font-size:22px;line-height:1.1}
            .dizzy-ticket-report-actions{padding:10px 12px}
            .dizzy-ticket-report-table-wrap{overflow-x:auto}.dizzy-ticket-report-table-wrap .widefat{border:0}.dizzy-ticket-report-table-wrap tr.is-calendar-hidden{display:none}
            .dizzy-ticket-calendar-toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px}
            .dizzy-ticket-calendar-nav{display:flex;align-items:center;gap:8px}
            .dizzy-ticket-calendar-month{min-width:150px;text-align:center;font-size:15px;font-weight:600;text-transform:capitalize}
            .dizzy-ticket-calendar-weekdays,.dizzy-ticket-calendar-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr))}
            .dizzy-ticket-calendar-weekdays div{padding:10px 4px;text-align:center;font-size:12px;font-weight:600;border-bottom:1px solid #dcdcde}
            .dizzy-ticket-calendar-day{position:relative;min-height:72px;padding:7px;border:0;border-right:1px solid #e2e4e7;border-bottom:1px solid #e2e4e7;background:#fff;color:#1d2327;text-align:left;cursor:pointer}
            .dizzy-ticket-calendar-day:nth-child(7n){border-right:0}.dizzy-ticket-calendar-day:hover{background:#f6f7f7}
            .dizzy-ticket-calendar-day.is-selected{background:#eef5ff;box-shadow:inset 0 0 0 2px #2271b1}
            .dizzy-ticket-calendar-day.is-other{color:#8c8f94;background:#fafafa}.dizzy-ticket-calendar-day-number{font-weight:600}
            .dizzy-ticket-calendar-count{display:block;width:max-content;max-width:100%;margin-top:15px;padding:3px 6px;border-radius:10px;background:#e7f3ff;font-size:10px;white-space:nowrap}
            .dizzy-ticket-calendar-count.is-busy{background:#fff0d5}
            @media(max-width:1300px){.dizzy-ticket-reports-workspace{grid-template-columns:minmax(0,1.35fr) minmax(400px,.9fr)}.dizzy-ticket-calendar-day{min-height:64px}}
            @media(max-width:1050px){.dizzy-ticket-reports-workspace{display:flex;flex-direction:column-reverse}.dizzy-ticket-report-list,.dizzy-ticket-report-calendar-column{width:100%}}
            @media(max-width:600px){.dizzy-ticket-calendar-day{min-height:52px;padding:4px}.dizzy-ticket-calendar-count{overflow:hidden;margin-top:5px;padding:0;width:8px;height:8px;text-indent:-9999px}.dizzy-ticket-calendar-toolbar{align-items:flex-start;flex-direction:column}}
        </style>
        <div class="dizzy-ticket-calendar-toolbar">
            <div class="dizzy-ticket-calendar-nav">
                <button type="button" class="button" id="dizzy-ticket-calendar-prev" aria-label="<?php esc_attr_e('Previous month', 'dizzy-ticket-manager'); ?>">‹</button>
                <div class="dizzy-ticket-calendar-month" id="dizzy-ticket-calendar-month"></div>
                <button type="button" class="button" id="dizzy-ticket-calendar-next" aria-label="<?php esc_attr_e('Next month', 'dizzy-ticket-manager'); ?>">›</button>
            </div>
            <button type="button" class="button" id="dizzy-ticket-calendar-today"><?php esc_html_e('Today', 'dizzy-ticket-manager'); ?></button>
        </div>
        <div class="dizzy-ticket-calendar-surface">
            <div class="dizzy-ticket-calendar-weekdays">
                <?php foreach ([__('Mon', 'dizzy-ticket-manager'), __('Tue', 'dizzy-ticket-manager'), __('Wed', 'dizzy-ticket-manager'), __('Thu', 'dizzy-ticket-manager'), __('Fri', 'dizzy-ticket-manager'), __('Sat', 'dizzy-ticket-manager'), __('Sun', 'dizzy-ticket-manager')] as $day) : ?><div><?php echo esc_html($day); ?></div><?php endforeach; ?>
            </div>
            <div class="dizzy-ticket-calendar-grid" id="dizzy-ticket-calendar-grid"></div>
        </div>
        <script>
        (() => {
            const rows = <?php echo wp_json_encode($rows); ?>;
            const grid = document.getElementById('dizzy-ticket-calendar-grid');
            if (!grid) return;
            const monthLabel = document.getElementById('dizzy-ticket-calendar-month');
            const listTitle = document.getElementById('dizzy-ticket-report-title');
            const showAll = document.getElementById('dizzy-ticket-report-show-all');
            const soldEl = document.getElementById('dizzy-report-sold');
            const attendedEl = document.getElementById('dizzy-report-attended');
            const revenueEl = document.getElementById('dizzy-report-revenue');
            const tableRows = Array.from(document.querySelectorAll('[data-ticket-report-date]'));
            const emptyRow = document.getElementById('dizzy-ticket-report-empty');
            const locale = <?php echo wp_json_encode(str_replace('_', '-', determine_locale())); ?>;
            const allLabel = <?php echo wp_json_encode(__('All Ticket Reports', 'dizzy-ticket-manager')); ?>;
            const ticketsLabel = <?php echo wp_json_encode(__('tickets', 'dizzy-ticket-manager')); ?>;
            const grouped = {};
            rows.forEach(row => {
                if (/^\d{4}-\d{2}-\d{2}$/.test(row.date)) {
                    grouped[row.date] ||= {sold:0, attended:0, revenue:0};
                    grouped[row.date].sold += Number(row.sold || 0);
                    grouped[row.date].attended += Number(row.attended || 0);
                    grouped[row.date].revenue += Number(row.revenue || 0);
                }
            });
            const now = new Date();
            let view = new Date(now.getFullYear(), now.getMonth(), 1);
            let selected = '';

            function localKey(date) {
                return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
            }

            function filterReport() {
                let visible = 0;
                tableRows.forEach(row => {
                    const show = !selected || row.dataset.ticketReportDate === selected;
                    row.classList.toggle('is-calendar-hidden', !show);
                    if (show) visible++;
                });
                emptyRow.hidden = !selected || visible > 0;
                const filtered = selected ? rows.filter(row => row.date === selected) : rows;
                soldEl.textContent = String(filtered.reduce((sum, row) => sum + Number(row.sold || 0), 0));
                attendedEl.textContent = String(filtered.reduce((sum, row) => sum + Number(row.attended || 0), 0));
                revenueEl.textContent = new Intl.NumberFormat(locale, {minimumFractionDigits:2, maximumFractionDigits:2}).format(filtered.reduce((sum, row) => sum + Number(row.revenue || 0), 0));
                showAll.hidden = !selected;
                listTitle.textContent = selected
                    ? new Intl.DateTimeFormat(locale, {weekday:'long', day:'numeric', month:'long', year:'numeric'}).format(new Date(selected + 'T12:00:00'))
                    : allLabel;
            }

            function renderCalendar() {
                monthLabel.textContent = new Intl.DateTimeFormat(locale, {month:'long', year:'numeric'}).format(view);
                grid.innerHTML = '';
                const first = new Date(view.getFullYear(), view.getMonth(), 1);
                const offset = (first.getDay() + 6) % 7;
                const start = new Date(view.getFullYear(), view.getMonth(), 1 - offset);
                for (let index = 0; index < 42; index++) {
                    const date = new Date(start);
                    date.setDate(start.getDate() + index);
                    const key = localKey(date);
                    const totals = grouped[key] || {sold:0, attended:0, revenue:0};
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'dizzy-ticket-calendar-day' + (date.getMonth() !== view.getMonth() ? ' is-other' : '') + (key === selected ? ' is-selected' : '');
                    button.setAttribute('aria-label', date.toDateString() + (totals.sold ? ', ' + totals.sold + ' ' + ticketsLabel : ''));
                    const number = document.createElement('span');
                    number.className = 'dizzy-ticket-calendar-day-number';
                    number.textContent = String(date.getDate());
                    button.appendChild(number);
                    if (totals.sold) {
                        const count = document.createElement('span');
                        count.className = 'dizzy-ticket-calendar-count' + (totals.sold >= 10 ? ' is-busy' : '');
                        count.textContent = totals.sold + ' ' + ticketsLabel;
                        button.appendChild(count);
                    }
                    button.addEventListener('click', () => {
                        selected = key;
                        if (date.getMonth() !== view.getMonth()) view = new Date(date.getFullYear(), date.getMonth(), 1);
                        renderCalendar();
                        filterReport();
                    });
                    grid.appendChild(button);
                }
            }

            showAll.addEventListener('click', () => { selected = ''; renderCalendar(); filterReport(); });
            document.getElementById('dizzy-ticket-calendar-prev').addEventListener('click', () => { view = new Date(view.getFullYear(), view.getMonth() - 1, 1); renderCalendar(); });
            document.getElementById('dizzy-ticket-calendar-next').addEventListener('click', () => { view = new Date(view.getFullYear(), view.getMonth() + 1, 1); renderCalendar(); });
            document.getElementById('dizzy-ticket-calendar-today').addEventListener('click', () => { const today = new Date(); view = new Date(today.getFullYear(), today.getMonth(), 1); selected = localKey(today); renderCalendar(); filterReport(); });
            renderCalendar();
            filterReport();
        })();
        </script>
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
        $this->guard(ControllerRole::TICKETS_CAP);
        $code = sanitize_text_field(wp_unslash((string) ($_POST['ticket_code'] ?? '')));
        if (! preg_match('/^[a-f0-9]{64}$/', $code)) wp_die(esc_html__('Invalid ticket.', 'dizzy-ticket-manager'));
        return $code;
    }

    private function redirect(string $page): never
    {
        wp_safe_redirect(admin_url('admin.php?page=' . $page));
        exit;
    }

    private function guard(string $capability = 'manage_options'): void
    {
        if (! current_user_can($capability)) wp_die(esc_html__('Unauthorized', 'dizzy-ticket-manager'));
    }
}
