<?php

declare(strict_types=1);

namespace Dizzy\Tickets;

use RuntimeException;

defined('ABSPATH') || exit;

final class TicketSalesService
{
    public function __construct(
        private EventGateway $events,
        private TicketSalesRepository $repository,
        private MollieClient $mollie,
        private Mailer $mailer
    ) {
    }

    /**
     * @return array{order_token:string,checkout_url:string}
     */
    public function start(array $data): array
    {
        $eventId = absint($data['event_id'] ?? 0);
        $typeId = absint($data['ticket_type_id'] ?? 0);
        $quantity = min(20, max(1, absint($data['quantity'] ?? 1)));
        $name = sanitize_text_field((string) ($data['name'] ?? ''));
        $email = sanitize_email((string) ($data['email'] ?? ''));
        $phone = sanitize_text_field((string) ($data['phone'] ?? ''));
        $type = $this->repository->findType($typeId);

        if (is_array($type) && (int) $type['event_id'] === $eventId) {
            $this->repository->syncFromEvent($eventId, (int) $type['occurrence_id']);
            $type = $this->repository->findType($typeId);
        }

        if (
            ! is_array($type)
            || (int) $type['event_id'] !== $eventId
            || (int) $type['active'] !== 1
            || $this->events->occurrence($eventId, (int) $type['occurrence_id']) === null
        ) {
            throw new RuntimeException('Selected ticket is unavailable.');
        }

        if ($name === '' || ! is_email($email)) {
            throw new RuntimeException('A valid name and email address are required.');
        }

        if ((float) $type['price'] <= 0) {
            throw new RuntimeException('Online sales require a ticket price above zero.');
        }

        if (! $this->mollie->configured()) {
            throw new RuntimeException('Mollie is not configured.');
        }

        $order = $this->repository->createPendingOrder(
            $type,
            $quantity,
            ['name' => $name, 'email' => $email, 'phone' => $phone]
        );

        $requestedReturnUrl = esc_url_raw((string) ($data['return_url'] ?? ''));
        $returnBase = wp_validate_redirect($requestedReturnUrl, home_url('/'));

        $returnUrl = add_query_arg(
            ['dizzy_tm_order' => $order['token']],
            $returnBase
        );

        try {
            $payment = $this->mollie->createPayment([
                'amount' => ['currency' => $order['currency'], 'value' => $order['total']],
                'description' => sprintf('Tickets: %s', get_the_title($eventId)),
                'redirectUrl' => $returnUrl,
                'webhookUrl' => rest_url('dizzy-tickets/v1/mollie/webhook'),
                'method' => 'ideal',
                'metadata' => ['order_id' => $order['id']],
            ]);
            $this->repository->addPayment($order['id'], $payment);
        } catch (RuntimeException $exception) {
            throw $exception;
        }

        $checkout = (string) ($payment['_links']['checkout']['href'] ?? '');

        if ($checkout === '') {
            throw new RuntimeException('Mollie did not return a checkout URL.');
        }

        return ['order_token' => $order['token'], 'checkout_url' => $checkout];
    }

    public function synchronize(string $paymentId): ?array
    {
        $stored = $this->repository->paymentByProviderId($paymentId);

        if ($stored === null) {
            return null;
        }

        $before = $this->repository->order((int) $stored['order_id']);
        $payment = $this->mollie->getPayment($paymentId);
        $order = $this->repository->applyPayment($payment);

        if (
            ($order['status'] ?? '') === 'paid'
            && $this->repository->claimConfirmationEmail((int) $order['id'])
        ) {
            if (! $this->sendTickets($order)) {
                $this->repository->releaseConfirmationEmail((int) $order['id']);
            }
        }

        return $order;
    }

    public function synchronizeOrder(array $order): array
    {
        if (($order['status'] ?? '') !== 'pending') {
            return $order;
        }

        $payment = $this->repository->paymentForOrder((int) $order['id']);

        if ($payment === null) {
            return $order;
        }

        return $this->synchronize((string) $payment['provider_payment_id']) ?? $order;
    }

    private function sendTickets(array $order): bool
    {
        global $wpdb;

        $ticketRows = $this->repository->ticketsForOrder((int) $order['id']);
        $itemsTable = $wpdb->prefix . 'dizzy_tm_ticket_order_items';
        $occurrencesTable = $wpdb->prefix . 'dizzy_event_occurrences';
        $tickets = [];

        foreach ($ticketRows as $index => $ticket) {
            $type = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT ticket_name FROM {$itemsTable} WHERE id=%d LIMIT 1",
                (int) $ticket['order_item_id']
            ));
            $code = (string) $ticket['ticket_code'];
            $tickets[] = [
                'code' => $code,
                'type' => $type !== '' ? $type : __('Event Ticket', 'dizzy-ticket-manager'),
                'url' => $this->ticketUrl($code),
                'label' => sprintf(__('Ticket %1$d · %2$s', 'dizzy-ticket-manager'), $index + 1, strtoupper(substr($code, 0, 12))),
            ];
        }

        $start = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT start_datetime FROM {$occurrencesTable} WHERE id=%d LIMIT 1",
            (int) $order['occurrence_id']
        ));
        $timestamp = $start !== '' ? strtotime($start) : false;
        $eventDate = $timestamp !== false ? wp_date('d/m/Y', $timestamp, wp_timezone()) : '';
        $eventTime = $timestamp !== false ? wp_date('H:i', $timestamp, wp_timezone()) : '';
        $ticketCount = count($tickets);

        $sent = $this->mailer->sendTemplate(
            (string) $order['customer_email'],
            __('Your event tickets', 'dizzy-ticket-manager'),
            'ticket-confirmed',
            [
                'order_id' => (int) $order['id'],
                'event_name' => get_the_title((int) $order['event_id']),
                'event_date' => $eventDate,
                'event_time' => $eventTime,
                'customer_name' => (string) $order['customer_name'],
                'customer_email' => (string) $order['customer_email'],
                'customer_phone' => (string) ($order['customer_phone'] ?? ''),
                'ticket_count' => $ticketCount,
                'total_amount' => (string) $order['total_amount'],
                'currency' => (string) $order['currency'],
                'tickets' => $tickets,

                // Compatibility aliases for the copied reservation template.
                'reservation_id' => (int) $order['id'],
                'name' => (string) $order['customer_name'],
                'email' => (string) $order['customer_email'],
                'phone' => (string) ($order['customer_phone'] ?? ''),
                'date' => $eventDate,
                'time' => $eventTime,
                'guests' => $ticketCount,
                'message' => '',
                'status' => 'paid',
            ]
        );

        if ($sent) {
            do_action('dizzy_ticket_purchased', [
                'order_id' => (int) $order['id'],
                'customer_name' => (string) $order['customer_name'],
                'customer_phone' => (string) ($order['customer_phone'] ?? ''),
                'event_name' => get_the_title((int) $order['event_id']),
                'event_date' => $eventDate,
                'event_time' => $eventTime,
                'ticket_count' => $ticketCount,
                'tickets' => $tickets,
            ]);
        }

        return $sent;
    }

    public function ticketUrl(string $code): string
    {
        return add_query_arg(['dizzy_tm_paid_ticket' => $code], home_url('/'));
    }
}
