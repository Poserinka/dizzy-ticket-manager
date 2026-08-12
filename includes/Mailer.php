<?php

declare(strict_types=1);

namespace Dizzy\Tickets;

use RuntimeException;

defined('ABSPATH') || exit;

final class Mailer
{
    public function send(string $email, string $subject, string $message): bool
    {
        return wp_mail($email, $subject, wp_kses_post($message), ['Content-Type: text/html; charset=UTF-8']);
    }

    public function sendTemplate(string $email, string $subject, string $template, array $data): bool
    {
        if (! preg_match('/^[a-z0-9-]+$/', $template)) {
            throw new RuntimeException('Invalid ticket email template name.');
        }

        $path = DIZZY_TICKETS_PATH . 'includes/Email/Templates/' . $template . '.php';

        if (! is_file($path)) {
            throw new RuntimeException('Ticket email template was not found: ' . $template);
        }

        $data = array_merge([
            'site_name' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            'site_url' => home_url('/'),
        ], $data);

        extract($data, EXTR_SKIP);
        ob_start();
        include $path;
        $html = (string) ob_get_clean();

        return wp_mail($email, $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
    }
}
