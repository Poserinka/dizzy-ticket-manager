<?php

declare(strict_types=1);

namespace Dizzy\Reservations;

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
            ['dizzy_order' => $order['token']],
            $returnBase
        );

        try {
            $payment = $this->mollie->createPayment([
                'amount' => ['currency' => $order['currency'], 'value' => $order['total']],
                'description' => sprintf('Tickets: %s', get_the_title($eventId)),
                'redirectUrl' => $returnUrl,
                'webhookUrl' => rest_url('dizzy-reservations/v1/mollie/webhook'),
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

        if (($before['status'] ?? '') !== 'paid' && ($order['status'] ?? '') === 'paid') {
            $this->sendTickets($order);
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

    private function sendTickets(array $order): void
    {
        $links = [];

        foreach ($this->repository->ticketsForOrder((int) $order['id']) as $ticket) {
            $links[] = '<li><a href="' . esc_url($this->ticketUrl((string) $ticket['ticket_code'])) . '">' .
                esc_html__('Open ticket', 'dizzy-reservations-manager') .
                '</a></li>';
        }

        $message = '<p>' . esc_html__('Your payment was received. Your tickets are ready:', 'dizzy-reservations-manager') . '</p><ul>' .
            implode('', $links) . '</ul>';

        $this->mailer->send(
            (string) $order['customer_email'],
            __('Your event tickets', 'dizzy-reservations-manager'),
            $message
        );
    }

    public function ticketUrl(string $code): string
    {
        return add_query_arg(['dizzy_paid_ticket' => $code], home_url('/'));
    }
}
