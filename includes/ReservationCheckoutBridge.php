<?php

declare(strict_types=1);

namespace Dizzy\Tickets;

use RuntimeException;

defined('ABSPATH') || exit;

final class ReservationCheckoutBridge
{
    public function __construct(
        private TicketSalesRepository $repository,
        private TicketSalesService $service
    ) {
    }

    public function register(): void
    {
        add_filter('dizzy_ticket_checkout_start', [$this, 'start'], 10, 2);
        add_filter('dizzy_ticket_checkout_status', [$this, 'status'], 10, 2);
    }

    public function start(mixed $result, array $data): array
    {
        $eventId = absint($data['event_id'] ?? 0);
        $occurrenceId = absint($data['occurrence_id'] ?? 0);
        if ($eventId < 1 || $occurrenceId < 1) {
            throw new RuntimeException('A valid concert is required for ticket checkout.');
        }

        $this->repository->syncFromEvent($eventId, $occurrenceId);
        $types = array_values(array_filter(
            $this->repository->activeTypes($eventId),
            static fn (array $type): bool => (int) $type['occurrence_id'] === $occurrenceId
        ));
        if ($types === []) {
            throw new RuntimeException('No ticket type is available for this concert.');
        }

        $type = $types[0];
        $standardPrice = trim(str_replace(',', '.', (string) get_post_meta($eventId, '_dizzy_standard_ticket_price', true)));
        if ($standardPrice === '') {
            $standardPrice = trim(str_replace(',', '.', (string) get_post_meta($eventId, '_dizzy_ticket_price', true)));
        }
        foreach ($types as $candidate) {
            if (is_numeric($standardPrice) && abs((float) $candidate['price'] - (float) $standardPrice) < 0.001) {
                $type = $candidate;
                break;
            }
        }

        return $this->service->start([
            'event_id' => $eventId,
            'ticket_type_id' => (int) $type['id'],
            'quantity' => absint($data['quantity'] ?? 1),
            'name' => (string) ($data['name'] ?? ''),
            'email' => (string) ($data['email'] ?? ''),
            'phone' => (string) ($data['phone'] ?? ''),
            'return_url' => (string) ($data['return_url'] ?? ''),
        ]);
    }

    public function status(mixed $result, string $token): ?array
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $order = $this->repository->orderByToken($token);
        if ($order === null) {
            return null;
        }
        return $this->service->synchronizeOrder($order);
    }
}
