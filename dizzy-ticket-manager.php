<?php
/**
 * Plugin Name: Dizzy Ticket Manager
 * Plugin URI: https://github.com/Poserinka/dizzy-ticket-manager
 * Description: Event ticket sales, Mollie iDEAL payments, QR tickets and check-in for Dizzy Events Manager.
 * Version: 1.1.1
 * Author: Poserinka Design
 * Text Domain: dizzy-ticket-manager
 * Requires PHP: 8.2
 * Update URI: https://github.com/Poserinka/dizzy-ticket-manager
 * Requires Plugins: dizzy-events-manager
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('DIZZY_TICKETS_VERSION', '1.1.1');
define('DIZZY_TICKETS_PATH', plugin_dir_path(__FILE__));

require_once DIZZY_TICKETS_PATH . 'includes/Autoloader.php';
\Dizzy\Tickets\Autoloader::register();

(new \Dizzy\Tickets\GitHubUpdater(
    __FILE__,
    'dizzy-ticket-manager',
    'Poserinka/dizzy-ticket-manager',
    DIZZY_TICKETS_VERSION
))->register();

register_activation_hook(__FILE__, [\Dizzy\Tickets\Database\Migrations::class, 'run']);

add_action('init', static function (): void {
    \Dizzy\Tickets\Database\Migrations::run();
    (new \Dizzy\Tickets\Plugin())->boot();
}, 20);
