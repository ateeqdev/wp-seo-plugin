<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\Sync;

use SEOAutomation\Connector\API\LaravelClient;
use SEOAutomation\Connector\Auth\SiteTokenManager;
use SEOAutomation\Connector\Utils\Logger;

final class SiteRegistrar
{
    private LaravelClient $client;

    private SiteTokenManager $tokenManager;

    private Logger $logger;

    public function __construct(LaravelClient $client, SiteTokenManager $tokenManager, Logger $logger)
    {
        $this->client = $client;
        $this->tokenManager = $tokenManager;
        $this->logger = $logger;
    }

    /**
     * @return array<string, mixed>
     */
    public function registerOrUpdate(): array
    {
        $siteId = (int) get_option('seoauto_site_id', 0);

        $payload = [
            'domain' => home_url('/'),
            'platform' => 'wordpress',
            'platform_version' => get_bloginfo('version'),
            'timezone' => wp_timezone_string(),
            'admin_email' => get_option('admin_email'),
            'users' => $this->collectUsers(),
        ];

        try {
            if ($siteId > 0 && $this->tokenManager->hasToken()) {
                $response = $this->client->updateSiteRegistration($siteId, $payload);
            } else {
                $response = $this->client->registerSite($payload);
                if (!empty($response['site_id'])) {
                    update_option('seoauto_site_id', (int) $response['site_id'], false);
                }
                if (!empty($response['api_key'])) {
                    $this->tokenManager->storeToken((string) $response['api_key']);
                }
            }

            return $response;
        } catch (\Throwable $exception) {
            $this->logger->error('site_registration_failed', [
                'error' => $exception->getMessage(),
                'request_payload' => $payload,
            ], 'outbound');

            return [
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function collectUsers(): array
    {
        $users = get_users([
            'fields' => ['ID', 'display_name', 'user_email', 'roles'],
        ]);

        $mapped = [];

        foreach ($users as $user) {
            $roles = is_array($user->roles) ? $user->roles : [];
            $mapped[] = [
                'name' => (string) $user->display_name,
                'email' => (string) $user->user_email,
                'platform_user_id' => (string) $user->ID,
                'role' => (string) ($roles[0] ?? 'editor'),
            ];
        }

        return $mapped;
    }
}
