<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SEOAutomation\Connector\Auth\OAuthCallbackState;

final class OAuthCallbackStateTest extends TestCase
{
    public function testFromQueryParsesSuccessState(): void
    {
        $state = OAuthCallbackState::fromQuery([
            'status' => 'success',
            'provider' => 'google',
            'connected_scopes' => 'search_console,analytics',
        ]);

        self::assertTrue($state->isSuccess());
        self::assertSame('google', $state->getProvider());
        self::assertSame(['search_console', 'analytics'], $state->getScopes());
    }

    public function testFromQueryParsesFailureState(): void
    {
        $state = OAuthCallbackState::fromQuery([
            'status' => 'failed',
            'provider' => 'google',
            'error' => 'access_denied',
        ]);

        self::assertFalse($state->isSuccess());
        self::assertSame('access_denied', $state->getError());
    }
}
