<?php

declare(strict_types=1);

namespace Dizzy\Tickets;

use WP_Error;
use WP_User;

defined('ABSPATH') || exit;

final class MobileAuth
{
    private const OPTION = 'dizzy_ticket_mobile_tokens';
    private const LIFETIME = 90 * DAY_IN_SECONDS;

    public function register(): void
    {
        add_filter('determine_current_user', [$this, 'authenticateToken'], 30);
    }

    public function login(string $username, string $password): array|WP_Error
    {
        $rateKey = $this->rateKey();
        $attempts = (int) get_transient($rateKey);

        if ($attempts >= 5) {
            return new WP_Error('dizzy_mobile_rate_limited', __('Too many login attempts. Please try again in 15 minutes.', 'dizzy-ticket-manager'), ['status' => 429]);
        }

        $user = wp_authenticate($username, $password);

        if ($user instanceof WP_Error || ! $user instanceof WP_User || ! in_array(ControllerRole::ROLE, (array) $user->roles, true)) {
            set_transient($rateKey, $attempts + 1, 15 * MINUTE_IN_SECONDS);
            return new WP_Error('dizzy_mobile_invalid_login', __('Invalid Controller username or password.', 'dizzy-ticket-manager'), ['status' => 401]);
        }

        delete_transient($rateKey);
        $token = bin2hex(random_bytes(32));
        $now = time();
        $tokens = $this->tokens();
        $tokens[hash('sha256', $token)] = [
            'user_id' => $user->ID,
            'created_at' => $now,
            'expires_at' => $now + self::LIFETIME,
            'last_used_at' => $now,
        ];
        update_option(self::OPTION, $tokens, false);

        return [
            'token' => $token,
            'expires_at' => gmdate('c', $now + self::LIFETIME),
            'user' => $this->userData($user),
        ];
    }

    public function logout(): void
    {
        $token = $this->bearerToken();
        if ($token === '') {
            return;
        }

        $tokens = $this->tokens();
        unset($tokens[hash('sha256', $token)]);
        update_option(self::OPTION, $tokens, false);
    }

    public function authenticateToken(mixed $userId): int|false
    {
        if ($userId) {
            return $userId;
        }

        $token = $this->bearerToken();
        if ($token === '') {
            return false;
        }

        $hash = hash('sha256', $token);
        $tokens = $this->tokens();
        $record = $tokens[$hash] ?? null;

        if (! is_array($record) || (int) ($record['expires_at'] ?? 0) < time()) {
            unset($tokens[$hash]);
            update_option(self::OPTION, $tokens, false);
            return false;
        }

        $user = get_user_by('id', (int) ($record['user_id'] ?? 0));
        if (! $user instanceof WP_User || ! in_array(ControllerRole::ROLE, (array) $user->roles, true) || ! user_can($user, ControllerRole::TICKETS_CAP)) {
            unset($tokens[$hash]);
            update_option(self::OPTION, $tokens, false);
            return false;
        }

        if ((int) ($record['last_used_at'] ?? 0) < time() - HOUR_IN_SECONDS) {
            $tokens[$hash]['last_used_at'] = time();
            update_option(self::OPTION, $tokens, false);
        }

        return $user->ID;
    }

    public function userData(WP_User $user): array
    {
        return [
            'id' => $user->ID,
            'name' => $user->display_name,
            'roles' => array_values((array) $user->roles),
        ];
    }

    private function tokens(): array
    {
        $tokens = get_option(self::OPTION, []);
        $tokens = is_array($tokens) ? $tokens : [];
        $now = time();
        return array_filter($tokens, static fn ($record): bool => is_array($record) && (int) ($record['expires_at'] ?? 0) >= $now);
    }

    private function bearerToken(): string
    {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        return preg_match('/^Bearer\s+([a-f0-9]{64})$/i', trim($header), $matches) === 1 ? strtolower($matches[1]) : '';
    }

    private function rateKey(): string
    {
        return 'dizzy_mobile_login_' . hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    }
}
