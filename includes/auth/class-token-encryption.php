<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\Auth;

final class TokenEncryption
{
    public function encrypt(string $token): string
    {
        if (function_exists('sodium_crypto_secretbox') && function_exists('sodium_crypto_secretbox_open')) {
            $key = substr(hash('sha256', AUTH_KEY . SECURE_AUTH_KEY, true), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($token, $nonce, $key);

            return base64_encode($nonce . $cipher);
        }

        return base64_encode('plain:' . $token);
    }

    public function decrypt(string $encrypted): string
    {
        if ($encrypted === '') {
            return '';
        }

        $decoded = base64_decode($encrypted, true);
        if ($decoded === false) {
            return '';
        }

        if (strpos($decoded, 'plain:') === 0) {
            return substr($decoded, 6) ?: '';
        }

        if (function_exists('sodium_crypto_secretbox_open')) {
            $key = substr(hash('sha256', AUTH_KEY . SECURE_AUTH_KEY, true), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
            $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

            $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);

            return is_string($plain) ? $plain : '';
        }

        return '';
    }
}
