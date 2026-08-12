<?php

declare(strict_types=1);

namespace Dizzy\Reservations\Database;

defined('ABSPATH') || exit;

final class Migrations
{
    private const VERSION = '2.0.0';

    public static function run(): void
    {
        if (version_compare((string) get_option('dizzy_reservations_db_version', '0'), self::VERSION, '>=')) {
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $reservations = $wpdb->prefix . 'dizzy_event_reservations';
        $capacities = $wpdb->prefix . 'dizzy_reservation_capacities';
        $ticketTypes = $wpdb->prefix . 'dizzy_ticket_types';
        $orders = $wpdb->prefix . 'dizzy_ticket_orders';
        $items = $wpdb->prefix . 'dizzy_ticket_order_items';
        $payments = $wpdb->prefix . 'dizzy_ticket_payments';
        $tickets = $wpdb->prefix . 'dizzy_tickets';
        $webhooks = $wpdb->prefix . 'dizzy_payment_webhooks';

        dbDelta("CREATE TABLE {$reservations} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id bigint(20) unsigned NOT NULL,
            occurrence_id bigint(20) unsigned NOT NULL,
            name varchar(190) NOT NULL,
            email varchar(190) NOT NULL,
            phone varchar(64) NULL,
            guests int(11) unsigned NOT NULL DEFAULT 1,
            status varchar(32) NOT NULL DEFAULT 'pending',
            checked_in_at datetime NULL,
            checked_in_by bigint(20) unsigned NULL,
            notes text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY event_id (event_id),
            KEY occurrence_id (occurrence_id),
            KEY status (status),
            KEY checked_in_at (checked_in_at),
            KEY email (email)
        ) {$charset};");

        dbDelta("CREATE TABLE {$capacities} (
            occurrence_id bigint(20) unsigned NOT NULL,
            capacity int(11) unsigned NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (occurrence_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$ticketTypes} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id bigint(20) unsigned NOT NULL,
            occurrence_id bigint(20) unsigned NOT NULL,
            name varchar(190) NOT NULL,
            price decimal(10,2) unsigned NOT NULL DEFAULT 0.00,
            currency char(3) NOT NULL DEFAULT 'EUR',
            capacity int(11) unsigned NULL,
            active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY event_occurrence (event_id,occurrence_id),
            KEY active (active)
        ) {$charset};");

        dbDelta("CREATE TABLE {$orders} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_token char(64) NOT NULL,
            event_id bigint(20) unsigned NOT NULL,
            occurrence_id bigint(20) unsigned NOT NULL,
            customer_name varchar(190) NOT NULL,
            customer_email varchar(190) NOT NULL,
            customer_phone varchar(64) NULL,
            status varchar(32) NOT NULL DEFAULT 'pending',
            total_amount decimal(10,2) unsigned NOT NULL,
            currency char(3) NOT NULL DEFAULT 'EUR',
            expires_at datetime NOT NULL,
            paid_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_token (public_token),
            KEY event_occurrence (event_id,occurrence_id),
            KEY status_expires (status,expires_at),
            KEY customer_email (customer_email)
        ) {$charset};");

        dbDelta("CREATE TABLE {$items} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            ticket_type_id bigint(20) unsigned NOT NULL,
            ticket_name varchar(190) NOT NULL,
            unit_price decimal(10,2) unsigned NOT NULL,
            quantity int(11) unsigned NOT NULL,
            line_total decimal(10,2) unsigned NOT NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY ticket_type_id (ticket_type_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$payments} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            provider varchar(32) NOT NULL DEFAULT 'mollie',
            provider_payment_id varchar(64) NOT NULL,
            status varchar(32) NOT NULL DEFAULT 'open',
            amount decimal(10,2) unsigned NOT NULL,
            currency char(3) NOT NULL DEFAULT 'EUR',
            raw_response longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY provider_payment (provider,provider_payment_id),
            KEY order_id (order_id),
            KEY status (status)
        ) {$charset};");

        dbDelta("CREATE TABLE {$tickets} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            order_item_id bigint(20) unsigned NOT NULL,
            event_id bigint(20) unsigned NOT NULL,
            occurrence_id bigint(20) unsigned NOT NULL,
            ticket_code char(64) NOT NULL,
            holder_name varchar(190) NOT NULL,
            holder_email varchar(190) NOT NULL,
            status varchar(32) NOT NULL DEFAULT 'valid',
            checked_in_at datetime NULL,
            checked_in_by bigint(20) unsigned NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY ticket_code (ticket_code),
            KEY order_id (order_id),
            KEY event_occurrence (event_id,occurrence_id),
            KEY status (status)
        ) {$charset};");

        dbDelta("CREATE TABLE {$webhooks} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            provider varchar(32) NOT NULL,
            provider_event_id varchar(190) NOT NULL,
            payload longtext NULL,
            processed_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY provider_event (provider,provider_event_id)
        ) {$charset};");

        update_option('dizzy_reservations_db_version', self::VERSION);
    }
}
