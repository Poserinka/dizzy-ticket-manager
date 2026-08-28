<?php

declare(strict_types=1);

namespace Dizzy\Tickets;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined('ABSPATH') || exit;

final class MobileApiController
{
    private const NAMESPACE = 'dizzy-controller/v1';

    public function __construct(private TicketSalesRepository $repository)
    {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        register_rest_route(self::NAMESPACE, '/tickets', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'tickets'],
            'permission_callback' => [$this, 'canManage'],
        ]);
        register_rest_route(self::NAMESPACE, '/ticket-orders', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'orders'],
            'permission_callback' => [$this, 'canManage'],
        ]);
        register_rest_route(self::NAMESPACE, '/attendance', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'attendance'],
            'permission_callback' => [$this, 'canManage'],
        ]);
        register_rest_route(self::NAMESPACE, '/check-in', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'checkIn'],
            'permission_callback' => [$this, 'canManage'],
            'args' => ['ticket' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field']],
        ]);
        register_rest_route(self::NAMESPACE, '/check-in/undo', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'undo'],
            'permission_callback' => [$this, 'canManage'],
            'args' => ['ticket' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field']],
        ]);
    }

    public function canManage(): bool
    {
        return current_user_can(ControllerRole::TICKETS_CAP);
    }

    public function tickets(WP_REST_Request $request): WP_REST_Response
    {
        $date = $this->requestedDate($request);

        return new WP_REST_Response(array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'code' => (string) $row['ticket_code'],
            'short_code' => strtoupper(substr((string) $row['ticket_code'], 0, 12)),
            'holder_name' => (string) $row['holder_name'],
            'holder_email' => (string) $row['holder_email'],
            'type' => (string) ($row['ticket_name'] ?? ''),
            'event' => (string) ($row['post_title'] ?? ''),
            'event_date' => (string) ($row['start_datetime'] ?? ''),
            'checked_in_at' => $row['checked_in_at'] ?: null,
        ], $this->repository->allTickets($date)));
    }

    public function orders(WP_REST_Request $request): WP_REST_Response
    {
        $date = $this->requestedDate($request);

        return new WP_REST_Response(array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'event_id' => (int) $row['event_id'],
            'event' => get_the_title((int) $row['event_id']),
            'customer_name' => (string) $row['customer_name'],
            'customer_email' => (string) $row['customer_email'],
            'customer_phone' => (string) ($row['customer_phone'] ?? ''),
            'status' => (string) $row['status'],
            'amount' => (string) $row['total_amount'],
            'currency' => (string) $row['currency'],
            'created_at' => (string) $row['created_at'],
        ], $this->repository->allOrders($date)));
    }

    public function attendance(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response($this->repository->attendanceTotals($this->requestedDate($request)));
    }

    public function checkIn(WP_REST_Request $request): WP_REST_Response
    {
        $code = $this->ticketCode((string) $request->get_param('ticket'));

        if ($code === '') {
            return new WP_REST_Response(['result' => 'invalid'], 400);
        }

        $ticket = $this->repository->ticketForCheckIn($code);

        if ($ticket === null || ($ticket['status'] ?? '') !== 'valid') {
            return new WP_REST_Response(['result' => 'invalid'], 404);
        }

        $eventDate = substr((string) ($ticket['start_datetime'] ?? ''), 0, 10);

        if ($eventDate === '' || $eventDate !== current_time('Y-m-d')) {
            return new WP_REST_Response([
                'result' => 'wrong_day',
                'event_date' => $eventDate,
                'event' => (string) ($ticket['post_title'] ?? ''),
            ], 409);
        }

        $result = $this->repository->checkInTicket($code, get_current_user_id());

        return new WP_REST_Response([
            'result' => $result,
            'holder_name' => (string) ($ticket['holder_name'] ?? ''),
            'event' => (string) ($ticket['post_title'] ?? ''),
            'event_date' => $eventDate,
        ], $result === 'invalid' ? 404 : 200);
    }

    public function undo(WP_REST_Request $request): WP_REST_Response
    {
        $code = $this->ticketCode((string) $request->get_param('ticket'));
        $ok = $code !== '' && $this->repository->undoCheckInTicket($code);
        return new WP_REST_Response(['ok' => $ok], $ok ? 200 : 400);
    }

    private function requestedDate(WP_REST_Request $request): string
    {
        $date = sanitize_text_field((string) $request->get_param('date'));

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return current_time('Y-m-d');
        }

        return $date;
    }

    private function ticketCode(string $value): string
    {
        $value = trim($value);

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            $query = [];
            parse_str((string) wp_parse_url($value, PHP_URL_QUERY), $query);
            $value = (string) ($query['dizzy_tm_paid_ticket'] ?? '');
        }

        return preg_match('/^[a-f0-9]{64}$/', $value) ? $value : '';
    }
}
