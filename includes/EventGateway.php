<?php

declare(strict_types=1);

namespace Dizzy\Reservations;

defined('ABSPATH') || exit;

final class EventGateway
{
    public const POST_TYPE = 'dizzy_event';

    public function available(): bool
    {
        return post_type_exists(self::POST_TYPE);
    }

    public function occurrence(int $eventId, int $occurrenceId): ?array
    {
        if ($eventId <= 0 || $occurrenceId <= 0 || get_post_type($eventId) !== self::POST_TYPE) {
            return null;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'dizzy_event_occurrences';
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id,event_id,start_datetime,end_datetime,timezone
                FROM {$table}
                WHERE id=%d AND event_id=%d AND status=%s
                LIMIT 1",
                $occurrenceId,
                $eventId,
                'publish'
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    public function upcoming(int $eventId): array
    {
        if (get_post_type($eventId) !== self::POST_TYPE) {
            return [];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'dizzy_event_occurrences';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    o.id,
                    o.event_id,
                    o.start_datetime,
                    o.end_datetime,
                    o.timezone,
                    p.post_title
                FROM {$table} o
                INNER JOIN {$wpdb->posts} p ON p.ID=o.event_id
                WHERE o.event_id=%d
                    AND o.status=%s
                    AND COALESCE(o.end_datetime,o.start_datetime)>=%s
                ORDER BY o.start_datetime",
                $eventId,
                'publish',
                current_time('mysql')
            ),
            ARRAY_A
        ) ?: [];
    }

    public function allUpcoming(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'dizzy_event_occurrences';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT o.id,o.event_id,o.start_datetime,o.end_datetime,o.timezone,p.post_title
                FROM {$table} o
                INNER JOIN {$wpdb->posts} p ON p.ID=o.event_id
                WHERE o.status=%s
                    AND p.post_type=%s
                    AND p.post_status IN ('publish','future','draft','pending','private')
                    AND COALESCE(o.end_datetime,o.start_datetime)>=%s
                ORDER BY o.start_datetime",
                'publish',
                self::POST_TYPE,
                current_time('mysql')
            ),
            ARRAY_A
        ) ?: [];
    }
}
