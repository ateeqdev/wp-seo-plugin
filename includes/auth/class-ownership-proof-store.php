<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\Auth;

final class OwnershipProofStore
{
    /**
     * @return array<string, array<string, mixed>>
     */
    private static function all(): array
    {
        $raw = get_option('seoworkerai_ownership_challenges', []);

        return is_array($raw) ? $raw : [];
    }

    public static function put(string $challengeId, string $challengeToken, int $expiresAtTs): void
    {
        $store = self::all();
        $store[$challengeId] = [
            'token' => $challengeToken,
            'expires_at' => $expiresAtTs,
        ];
        update_option('seoworkerai_ownership_challenges', $store, false);
    }

    public static function getToken(string $challengeId): ?string
    {
        $store = self::all();
        if (!isset($store[$challengeId]) || !is_array($store[$challengeId])) {
            return null;
        }

        $entry = $store[$challengeId];
        $expiresAt = isset($entry['expires_at']) ? (int) $entry['expires_at'] : 0;
        if ($expiresAt > 0 && $expiresAt < time()) {
            self::delete($challengeId);
            return null;
        }

        $token = isset($entry['token']) ? (string) $entry['token'] : '';

        return $token !== '' ? $token : null;
    }

    public static function delete(string $challengeId): void
    {
        $store = self::all();
        unset($store[$challengeId]);
        update_option('seoworkerai_ownership_challenges', $store, false);
    }

    public static function cleanup(): void
    {
        $store = self::all();
        $now = time();
        foreach ($store as $challengeId => $entry) {
            if (!is_array($entry)) {
                unset($store[$challengeId]);
                continue;
            }

            $expiresAt = isset($entry['expires_at']) ? (int) $entry['expires_at'] : 0;
            if ($expiresAt > 0 && $expiresAt < $now) {
                unset($store[$challengeId]);
            }
        }

        update_option('seoworkerai_ownership_challenges', $store, false);
    }
}
