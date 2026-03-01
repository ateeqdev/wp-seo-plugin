<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\Auth;

final class OAuthCallbackState
{
    private string $status;

    private string $provider;

    /**
     * @var string[]
     */
    private array $scopes;

    private string $error;

    /**
     * @param string[] $scopes
     */
    public function __construct(string $status, string $provider, array $scopes = [], string $error = '')
    {
        $this->status = self::sanitize($status);
        $this->provider = self::sanitize($provider);
        $this->scopes = array_values(array_filter(array_map([self::class, 'sanitize'], $scopes)));
        $this->error = self::sanitize($error);
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query): self
    {
        $status = self::sanitize((string) ($query['status'] ?? 'failed'));
        $provider = self::sanitize((string) ($query['provider'] ?? 'google'));
        $error = self::sanitize((string) ($query['error'] ?? ''));

        $scopeString = (string) ($query['connected_scopes'] ?? '');
        $scopes = [];

        if ($scopeString !== '') {
            $scopes = array_filter(array_map('trim', explode(',', $scopeString)));
        }

        return new self($status, $provider, $scopes, $error);
    }

    private static function sanitize(string $value): string
    {
        $value = strip_tags($value);
        $value = preg_replace('/[\\x00-\\x1F\\x7F]/u', '', $value);

        return trim(is_string($value) ? $value : '');
    }

    public function isSuccess(): bool
    {
        return $this->status === 'success';
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    /**
     * @return string[]
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function getError(): string
    {
        return $this->error;
    }
}
