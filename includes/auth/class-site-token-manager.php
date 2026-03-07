<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\Auth;

final class SiteTokenManager
{
    private TokenEncryption $encryption;

    public function __construct(TokenEncryption $encryption)
    {
        $this->encryption = $encryption;
    }

    public function storeToken(string $token): void
    {
        update_option('seoworkerai_site_token', $this->encryption->encrypt($token), false);
    }

    public function getToken(): ?string
    {
        $encrypted = (string) get_option('seoworkerai_site_token', '');

        if ($encrypted === '') {
            return null;
        }

        $token = $this->encryption->decrypt($encrypted);

        return $token !== '' ? $token : null;
    }

    public function hasToken(): bool
    {
        return $this->getToken() !== null;
    }

    public function clearToken(): void
    {
        delete_option('seoworkerai_site_token');
    }

    public function verifyInboundToken(string $provided): bool
    {
        $expected = $this->getToken();

        if ($expected === null || $provided === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }
}
