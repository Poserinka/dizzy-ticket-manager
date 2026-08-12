<?php
/**
 * Plugin Name: Dizzy Reservations Manager
 * Plugin URI: https://github.com/Poserinka/dizzy-reservations-manager
 * Description: Reservations, Mollie iDEAL ticket sales, QR tickets and check-in for Dizzy Events Manager.
 * Version: 2.3.7
 * Author: Poserinka Design
 * Text Domain: dizzy-reservations-manager
 * Requires PHP: 8.2
 * Update URI: https://github.com/Poserinka/dizzy-reservations-manager
 * Requires Plugins: dizzy-events-manager
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('DIZZY_RESERVATIONS_VERSION', '2.3.7');
define('DIZZY_RESERVATIONS_PATH', plugin_dir_path(__FILE__));

require_once DIZZY_RESERVATIONS_PATH . 'includes/Autoloader.php';
\Dizzy\Reservations\Autoloader::register();

(new \Dizzy\Reservations\GitHubUpdater(
    __FILE__,
    'dizzy-reservations-manager',
    'Poserinka/dizzy-reservations-manager',
    DIZZY_RESERVATIONS_VERSION
))->register();

register_activation_hook(__FILE__, [\Dizzy\Reservations\Database\Migrations::class, 'run']);

add_action('init', static function (): void {
    \Dizzy\Reservations\Database\Migrations::run();
    (new \Dizzy\Reservations\Plugin())->boot();
}, 20);
