<?php

declare(strict_types=1);

namespace Dizzy\Reservations;

use RuntimeException;

defined('ABSPATH') || exit;

final class MollieClient
{
    private const API = 'https://api.mollie.com/v2';

    public function configured(): bool
    {
        return $this->apiKey() !== '';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createPayment(array $payload): array
    {
        return $this->request('POST', '/payments', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayment(string $paymentId): array
    {
        if (! preg_match('/^tr_[A-Za-z0-9]+$/', $paymentId)) {
            throw new RuntimeException('Invalid Mollie payment identifier.');
        }

        return $this->request('GET', '/payments/' . rawurlencode($paymentId));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $payload = []): array
    {
        $key = $this->apiKey();

        if ($key === '') {
            throw new RuntimeException('Mollie API key is not configured.');
        }

        $args = [
            'method' => $method,
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'Accept' => 'application/hal+json',
                'Content-Type' => 'application/json',
            ],
        ];

        if ($payload !== []) {
            $args['body'] = wp_json_encode($payload, JSON_UNESCAPED_SLASHES);
        }

        $response = wp_remote_request(self::API . $path, $args);

        if (is_wp_error($response)) {
            throw new RuntimeException('Mollie request failed: ' . $response->get_error_message());
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status < 200 || $status >= 300 || ! is_array($body)) {
            $detail = is_array($body) ? (string) ($body['detail'] ?? $body['title'] ?? '') : '';
            throw new RuntimeException('Mollie API returned HTTP ' . $status . ($detail !== '' ? ': ' . $detail : '.'));
        }

        return $body;
    }

    private function apiKey(): string
    {
        $key = trim((string) get_option('dizzy_mollie_api_key', ''));

        return preg_match('/^(test|live)_[A-Za-z0-9]+$/', $key) ? $key : '';
    }
}
