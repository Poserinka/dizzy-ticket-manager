<?php

declare(strict_types=1);

namespace Dizzy\Tickets;

defined('ABSPATH') || exit;

final class ControllerRole
{
    public const ROLE = 'dizzy_controller';
    public const RESERVATIONS_CAP = 'dizzy_manage_reservations';
    public const TICKETS_CAP = 'dizzy_manage_tickets';

    public function register(): void
    {
        $role = get_role(self::ROLE);

        if ($role === null) {
            add_role(self::ROLE, __('Controller', 'dizzy-ticket-manager'), [
                'read' => true,
                self::TICKETS_CAP => true,
            ]);
            $role = get_role(self::ROLE);
        }

        $role?->add_cap('read');
        $role?->add_cap(self::TICKETS_CAP);
        get_role('administrator')?->add_cap(self::TICKETS_CAP);

        add_action('admin_menu', [$this, 'limitMenus'], 999);
        add_action('admin_init', [$this, 'redirectDashboard']);
    }

    public function limitMenus(): void
    {
        if (! $this->isController()) {
            return;
        }

        global $menu;
        $allowed = ['dizzy-reservations', 'dizzy-tickets'];

        foreach ((array) $menu as $item) {
            $slug = (string) ($item[2] ?? '');
            if ($slug !== '' && ! in_array($slug, $allowed, true)) {
                remove_menu_page($slug);
            }
        }
    }

    public function redirectDashboard(): void
    {
        if (! $this->isController() || ($GLOBALS['pagenow'] ?? '') !== 'index.php') {
            return;
        }

        $page = current_user_can(self::RESERVATIONS_CAP) ? 'dizzy-reservations' : 'dizzy-tickets';
        wp_safe_redirect(admin_url('admin.php?page=' . $page));
        exit;
    }

    private function isController(): bool
    {
        $user = wp_get_current_user();
        return in_array(self::ROLE, (array) $user->roles, true);
    }
}
