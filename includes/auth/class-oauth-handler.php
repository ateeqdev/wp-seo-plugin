<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\Auth;

use RuntimeException;
use SEOAutomation\Connector\API\LaravelClient;
use SEOAutomation\Connector\Sync\HealthChecker;
use SEOAutomation\Connector\Utils\Logger;

final class OAuthHandler
{
    private LaravelClient $client;

    private HealthChecker $healthChecker;

    private Logger $logger;

    public function __construct(LaravelClient $client, HealthChecker $healthChecker, Logger $logger)
    {
        $this->client = $client;
        $this->healthChecker = $healthChecker;
        $this->logger = $logger;
    }

    /**
     * @param string[] $scopes
     */
    public function beginGoogleOAuth(array $scopes = ['search_console', 'analytics']): string
    {
        $siteId = (int) get_option('seoauto_site_id', 0);

        if ($siteId <= 0) {
            throw new RuntimeException('Site must be registered before OAuth.');
        }

        $payload = [
            'return_url' => admin_url('admin.php?page=seoauto-oauth-callback'),
            'scopes' => array_values(array_filter(array_map('sanitize_text_field', $scopes))),
        ];

        $response = $this->client->initializeGoogleOAuth($payload);
        $oauthUrl = isset($response['oauth_url']) ? esc_url_raw((string) $response['oauth_url']) : '';

        if ($oauthUrl === '') {
            throw new RuntimeException('Laravel did not return an oauth_url.');
        }

        update_option('seoauto_oauth_status', 'in_progress', false);
        update_option('seoauto_oauth_last_error', '', false);

        return $oauthUrl;
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function handleCallback(array $query): array
    {
        $state = OAuthCallbackState::fromQuery($query);

        if ($state->isSuccess()) {
            update_option('seoauto_oauth_status', 'active', false);
            update_option('seoauto_oauth_provider', $state->getProvider(), false);
            update_option('seoauto_oauth_scopes', $state->getScopes(), false);
            update_option('seoauto_oauth_connected_at', time(), false);
            update_option('seoauto_oauth_last_error', '', false);

            $health = $this->healthChecker->check();

            if (empty($health['connected'])) {
                update_option('seoauto_oauth_status', 'error', false);
                $message = 'OAuth completed but health check failed.';
                update_option('seoauto_oauth_last_error', $message, false);

                return [
                    'status' => 'error',
                    'provider' => $state->getProvider(),
                    'scopes' => $state->getScopes(),
                    'health' => $health,
                    'error' => $message,
                ];
            }

            return [
                'status' => 'active',
                'provider' => $state->getProvider(),
                'scopes' => $state->getScopes(),
                'health' => $health,
                'error' => '',
            ];
        }

        $error = $state->getError() !== '' ? $state->getError() : 'OAuth callback indicated failure.';

        update_option('seoauto_oauth_status', 'failed', false);
        update_option('seoauto_oauth_provider', $state->getProvider(), false);
        update_option('seoauto_oauth_scopes', [], false);
        update_option('seoauto_oauth_last_error', $error, false);

        $this->logger->warning('oauth_callback_failed', [
            'entity_type' => 'oauth',
            'entity_id' => $state->getProvider(),
            'error' => $error,
        ], 'inbound');

        return [
            'status' => 'failed',
            'provider' => $state->getProvider(),
            'scopes' => [],
            'health' => [],
            'error' => $error,
        ];
    }
}
