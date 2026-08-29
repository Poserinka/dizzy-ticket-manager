<?php

declare(strict_types=1);

namespace Dizzy\Tickets;

defined('ABSPATH') || exit;

final class Plugin
{
    private static bool $booted = false;

    public function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;
        (new ControllerRole())->register();
        $events = new EventGateway();

        if (! $events->available()) {
            add_action('admin_notices', static function (): void {
                echo '<div class="notice notice-error"><p>' .
                    esc_html__('Dizzy Ticket Manager requires Dizzy Events Manager to be active.', 'dizzy-ticket-manager') .
                    '</p></div>';
            });
            return;
        }

        $repository = new TicketSalesRepository();
        $mailer = new Mailer();
        $mollie = new MollieClient();
        $service = new TicketSalesService($events, $repository, $mollie, $mailer);

        (new TicketSalesController($repository, $service, $events))->register();
        (new MobileApiController($repository))->register();
        (new CheckInWebApp())->register();
        (new CheckInRoute())->register();
        (new TicketExperience($repository))->register();

        if (is_admin()) {
            (new TicketSalesAdmin($repository, $events))->register();
        }
    }
}
