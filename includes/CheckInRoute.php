<?php

declare(strict_types=1);

namespace Dizzy\Tickets;

defined('ABSPATH') || exit;

final class CheckInRoute
{
    public function register(): void
    {
        add_action('template_redirect', [$this, 'dispatch'], 0);
    }

    public function dispatch(): void
    {
        $requestPath = (string) wp_parse_url(
            (string) wp_unslash($_SERVER['REQUEST_URI'] ?? '/'),
            PHP_URL_PATH
        );
        $checkInPath = (string) wp_parse_url(home_url('/check-in/'), PHP_URL_PATH);

        if (untrailingslashit($requestPath) !== untrailingslashit($checkInPath)) {
            return;
        }

        $GLOBALS['wp_query']->set('dizzy_ticket_checkin_app', '1');
        (new CheckInWebApp())->render();
    }
}
