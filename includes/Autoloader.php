<?php

declare(strict_types=1);

namespace Dizzy\Reservations;

defined('ABSPATH') || exit;

final class Autoloader
{
    public static function register(): void
    {
        spl_autoload_register(static function (string $class): void {
            $prefix = __NAMESPACE__ . '\\';
            if (! str_starts_with($class, $prefix)) return;
            $file = DIZZY_RESERVATIONS_PATH . 'includes/' . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))) . '.php';
            if (is_readable($file)) require_once $file;
        });
    }
}
