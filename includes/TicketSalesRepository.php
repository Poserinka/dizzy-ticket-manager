<?php

declare(strict_types=1);

namespace Dizzy\Reservations;

use RuntimeException;
use Throwable;

defined('ABSPATH') || exit;

final class TicketSalesRepository
{
    private string $types;
    private string $orders;
    private string $items;
    private string $payments;
    private string $tickets;
    private string $webhooks;

    public function __construct()
    {
        global $wpdb;
        $this->types = $wpdb->prefix . 'dizzy_ticket_types';
        $this->orders = $wpdb->prefix . 'dizzy_ticket_orders';
        $this->items = $wpdb->prefix . 'dizzy_ticket_order_items';
        $this->payments = $wpdb->prefix . 'dizzy_ticket_payments';
        $this->tickets = $wpdb->prefix . 'dizzy_tickets';
        $this->webhooks = $wpdb->prefix . 'dizzy_payment_webhooks';
    }

    public function syncFromEvent(int $eventId, int $occurrenceId): void
    {
        global $wpdb;

        $legacyPrice = (string) get_post_meta($eventId, '_dizzy_ticket_price', true);
        $prices = [
            __('Standard Ticket', 'dizzy-reservations-manager') => (string) get_post_meta($eventId, '_dizzy_standard_ticket_price', true),
            __('Student Ticket', 'dizzy-reservations-manager') => (string) get_post_meta($eventId, '_dizzy_student_ticket_price', true),
        ];

        if (trim($prices[__('Standard Ticket', 'dizzy-reservations-manager')]) === '') {
            $prices[__('Standard Ticket', 'dizzy-reservations-manager')] = $legacyPrice;
        }

        $capacity = absint(get_post_meta($eventId, '_dizzy_capacity', true));
        $activeIds = [];

        foreach ($prices as $name => $rawPrice) {
            $rawPrice = trim(str_replace(',', '.', $rawPrice));
            $existingId = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$this->types} WHERE event_id=%d AND name=%s ORDER BY id LIMIT 1",
                    $eventId,
                    $name
                )
            );

            if ($existingId === 0 && $name === __('Standard Ticket', 'dizzy-reservations-manager')) {
                $existingId = (int) $wpdb->get_var(
                    $wpdb->prepare("SELECT id FROM {$this->types} WHERE event_id=%d ORDER BY id LIMIT 1", $eventId)
                );
            }

            if ($rawPrice === '' || ! is_numeric($rawPrice) || (float) $rawPrice <= 0) {
                if ($existingId > 0) {
                    $wpdb->update(
                        $this->types,
                        ['active' => 0, 'updated_at' => current_time('mysql', true)],
                        ['id' => $existingId]
                    );
                }
                continue;
            }

            $activeIds[] = $this->saveType([
                'id' => $existingId,
                'event_id' => $eventId,
                'occurrence_id' => $occurrenceId,
                'name' => $name,
                'price' => $rawPrice,
                'capacity' => $capacity,
                'active' => true,
            ]);
        }

        if ($activeIds === []) {
            $wpdb->update(
                $this->types,
                ['active' => 0, 'updated_at' => current_time('mysql', true)],
                ['event_id' => $eventId]
            );
            return;
        }

        $placeholders = implode(',', array_fill(0, count($activeIds), '%d'));
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->types} SET active=0,updated_at=%s
                WHERE event_id=%d AND id NOT IN ({$placeholders})",
                current_time('mysql', true),
                $eventId,
                ...$activeIds
            )
        );
    }

    public function activeTypes(int $eventId): array
    {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->types} WHERE event_id=%d AND active=1 ORDER BY price,name",
                $eventId
            ),
            ARRAY_A
        ) ?: [];
    }

    public function allTypes(): array
    {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->types} ORDER BY event_id,id", ARRAY_A) ?: [];
    }

    public function findType(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->types} WHERE id=%d", $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public function saveType(array $data): int
    {
        global $wpdb;
        $id = absint($data['id'] ?? 0);
        $now = current_time('mysql', true);
        $record = [
            'event_id' => absint($data['event_id']),
            'occurrence_id' => absint($data['occurrence_id']),
            'name' => sanitize_text_field((string) $data['name']),
            'price' => number_format(max(0, (float) $data['price']), 2, '.', ''),
            'currency' => 'EUR',
            'capacity' => absint($data['capacity']) > 0 ? absint($data['capacity']) : null,
            'active' => ! empty($data['active']) ? 1 : 0,
            'updated_at' => $now,
        ];

        if ($id > 0) {
            if ($wpdb->update($this->types, $record, ['id' => $id]) === false) {
                throw new RuntimeException('Ticket type could not be updated: ' . $wpdb->last_error);
            }
            return $id;
        }

        $record['created_at'] = $now;

        if ($wpdb->insert($this->types, $record) === false) {
            throw new RuntimeException('Ticket type could not be created: ' . $wpdb->last_error);
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * @return array{id:int,token:string,total:string,currency:string}
     */
    public function createPendingOrder(array $type, int $quantity, array $customer): array
    {
        global $wpdb;

        if ($wpdb->query('START TRANSACTION') === false) {
            throw new RuntimeException('Could not start ticket order transaction.');
        }

        try {
            $locked = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$this->types} WHERE id=%d AND active=1 FOR UPDATE", (int) $type['id']),
                ARRAY_A
            );

            if (! is_array($locked)) {
                throw new RuntimeException('Ticket type is no longer available.');
            }

            $capacity = (int) ($locked['capacity'] ?? 0);

            if ($capacity > 0) {
                $reserved = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COALESCE(SUM(i.quantity),0)
                        FROM {$this->items} i
                        INNER JOIN {$this->orders} o ON o.id=i.order_id
                        WHERE o.event_id=%d
                        AND (o.status='paid' OR (o.status='pending' AND o.expires_at>%s))",
                        (int) $locked['event_id'],
                        current_time('mysql', true)
                    )
                );

                if ($reserved + $quantity > $capacity) {
                    throw new RuntimeException('Not enough tickets are available.');
                }
            }

            $now = current_time('mysql', true);
            $holdMinutes = min(60, max(5, (int) get_option('dizzy_ticket_hold_minutes', 15)));
            $expires = gmdate('Y-m-d H:i:s', time() + $holdMinutes * MINUTE_IN_SECONDS);
            $token = bin2hex(random_bytes(32));
            $total = number_format((float) $locked['price'] * $quantity, 2, '.', '');

            $inserted = $wpdb->insert($this->orders, [
                'public_token' => $token,
                'event_id' => (int) $locked['event_id'],
                'occurrence_id' => (int) $locked['occurrence_id'],
                'customer_name' => $customer['name'],
                'customer_email' => $customer['email'],
                'customer_phone' => $customer['phone'],
                'status' => 'pending',
                'total_amount' => $total,
                'currency' => 'EUR',
                'expires_at' => $expires,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted === false) {
                throw new RuntimeException('Ticket order could not be created: ' . $wpdb->last_error);
            }

            $orderId = (int) $wpdb->insert_id;
            $line = $wpdb->insert($this->items, [
                'order_id' => $orderId,
                'ticket_type_id' => (int) $locked['id'],
                'ticket_name' => (string) $locked['name'],
                'unit_price' => (string) $locked['price'],
                'quantity' => $quantity,
                'line_total' => $total,
            ]);

            if ($line === false || $wpdb->query('COMMIT') === false) {
                throw new RuntimeException('Ticket order item could not be saved: ' . $wpdb->last_error);
            }

            return ['id' => $orderId, 'token' => $token, 'total' => $total, 'currency' => 'EUR'];
        } catch (Throwable $exception) {
            $wpdb->query('ROLLBACK');
            throw $exception;
        }
    }

    public function addPayment(int $orderId, array $payment): void
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $ok = $wpdb->insert($this->payments, [
            'order_id' => $orderId,
            'provider' => 'mollie',
            'provider_payment_id' => (string) $payment['id'],
            'status' => (string) ($payment['status'] ?? 'open'),
            'amount' => (string) $payment['amount']['value'],
            'currency' => (string) $payment['amount']['currency'],
            'raw_response' => wp_json_encode($payment),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($ok === false) {
            throw new RuntimeException('Payment record could not be saved: ' . $wpdb->last_error);
        }
    }

    public function paymentByProviderId(string $paymentId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->payments} WHERE provider='mollie' AND provider_payment_id=%s", $paymentId),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    public function order(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->orders} WHERE id=%d", $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public function orderByToken(string $token): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->orders} WHERE public_token=%s", $token), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public function paymentForOrder(int $orderId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->payments} WHERE order_id=%d ORDER BY id DESC LIMIT 1", $orderId), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public function ticketsForOrder(int $orderId): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tickets} WHERE order_id=%d ORDER BY id", $orderId), ARRAY_A) ?: [];
    }

    public function ticketByCode(string $code): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tickets} WHERE ticket_code=%s", $code), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public function applyPayment(array $payment): array
    {
        global $wpdb;
        $providerId = (string) $payment['id'];

        if ($wpdb->query('START TRANSACTION') === false) {
            throw new RuntimeException('Could not start payment transaction.');
        }

        try {
            $stored = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$this->payments} WHERE provider='mollie' AND provider_payment_id=%s FOR UPDATE", $providerId),
                ARRAY_A
            );

            if (! is_array($stored)) {
                throw new RuntimeException('Unknown Mollie payment.');
            }

            $order = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$this->orders} WHERE id=%d FOR UPDATE", (int) $stored['order_id']),
                ARRAY_A
            );

            if (! is_array($order)) {
                throw new RuntimeException('Payment order does not exist.');
            }

            $metadataOrder = absint($payment['metadata']['order_id'] ?? 0);

            if (
                $metadataOrder !== (int) $order['id']
                || (string) $payment['amount']['currency'] !== (string) $order['currency']
                || ! hash_equals((string) $order['total_amount'], (string) $payment['amount']['value'])
            ) {
                throw new RuntimeException('Mollie payment does not match the local order.');
            }

            $status = sanitize_key((string) ($payment['status'] ?? 'open'));
            $now = current_time('mysql', true);
            $wpdb->update($this->payments, ['status' => $status, 'raw_response' => wp_json_encode($payment), 'updated_at' => $now], ['id' => (int) $stored['id']]);

            if ($status === 'paid' && $order['status'] !== 'paid') {
                $wpdb->update($this->orders, ['status' => 'paid', 'paid_at' => $now, 'updated_at' => $now], ['id' => (int) $order['id']]);
                $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->items} WHERE order_id=%d", (int) $order['id']), ARRAY_A) ?: [];

                foreach ($items as $item) {
                    for ($number = 0; $number < (int) $item['quantity']; $number++) {
                        $wpdb->insert($this->tickets, [
                            'order_id' => (int) $order['id'],
                            'order_item_id' => (int) $item['id'],
                            'event_id' => (int) $order['event_id'],
                            'occurrence_id' => (int) $order['occurrence_id'],
                            'ticket_code' => bin2hex(random_bytes(32)),
                            'holder_name' => (string) $order['customer_name'],
                            'holder_email' => (string) $order['customer_email'],
                            'status' => 'valid',
                            'created_at' => $now,
                        ]);
                    }
                }
            } elseif (in_array($status, ['failed', 'canceled', 'expired'], true) && $order['status'] === 'pending') {
                $wpdb->update($this->orders, ['status' => $status, 'updated_at' => $now], ['id' => (int) $order['id']]);
            }

            if ($wpdb->query('COMMIT') === false) {
                throw new RuntimeException('Could not commit payment transaction.');
            }

            return $this->order((int) $order['id']) ?? $order;
        } catch (Throwable $exception) {
            $wpdb->query('ROLLBACK');
            throw $exception;
        }
    }

    public function checkInTicket(string $code, int $userId): string
    {
        global $wpdb;
        $ticket = $this->ticketByCode($code);

        if ($ticket === null || ($ticket['status'] ?? '') !== 'valid') {
            return 'invalid';
        }

        if (! empty($ticket['checked_in_at'])) {
            return 'already_checked_in';
        }

        $updated = $wpdb->update(
            $this->tickets,
            [
                'checked_in_at' => current_time('mysql', true),
                'checked_in_by' => $userId,
            ],
            [
                'id' => (int) $ticket['id'],
                'status' => 'valid',
                'checked_in_at' => null,
            ]
        );

        return $updated === 1 ? 'checked_in' : 'already_checked_in';
    }

    public function allOrders(): array
    {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->orders} ORDER BY created_at DESC LIMIT 500", ARRAY_A) ?: [];
    }
}
