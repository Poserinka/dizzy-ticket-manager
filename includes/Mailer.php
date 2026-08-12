<?php

declare(strict_types=1);

namespace Dizzy\Reservations;

defined('ABSPATH') || exit;

final class Mailer
{
    public function send(string $email,string $subject,string $message): bool
    {
        return wp_mail($email,$subject,wp_kses_post($message),['Content-Type: text/html; charset=UTF-8']);
    }
}
