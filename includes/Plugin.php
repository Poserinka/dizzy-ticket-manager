<?php

declare(strict_types=1);

namespace Dizzy\Reservations;

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
        $events = new EventGateway();

        if (! $events->available()) {
            add_action('admin_notices', static function (): void {
                echo '<div class="notice notice-error"><p>' .
                    esc_html__('Dizzy Reservations Manager requires Dizzy Events Manager to be active.', 'dizzy-reservations-manager') .
                    '</p></div>';
            });
            return;
        }

        $repository = new ReservationRepository();
        $mailer = new Mailer();
        $tickets = new TicketService($repository);
        $reservationService = new ReservationService($events, $repository, $mailer, $tickets);

        $salesRepository = new TicketSalesRepository();
        $mollie = new MollieClient();
        $salesService = new TicketSalesService($events, $salesRepository, $mollie, $mailer);
        $ticketExperience = new TicketExperience($salesRepository);

        (new FrontendController($events, $reservationService))->register();
        (new TicketSalesController($salesRepository, $salesService, $events))->register();
        $ticketExperience->register();
        $tickets->register();

        if (is_admin()) {
            (new AdminController($repository, $reservationService, $tickets, $events))->register();
            (new TicketSalesAdmin($salesRepository, $events))->register();
        }
    }
}
