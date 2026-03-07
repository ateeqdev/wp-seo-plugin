<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\Sync;

use SEOAutomation\Connector\API\LaravelClient;
use SEOAutomation\Connector\Utils\Logger;

final class UserSyncer
{
    private LaravelClient $client;

    private Logger $logger;

    public function __construct(LaravelClient $client, Logger $logger)
    {
        $this->client = $client;
        $this->logger = $logger;
    }

    public function sync(): void
    {
        $siteId = (int) get_option('seoauto_site_id', 0);

        if ($siteId <= 0) {
            return;
        }

        $users = get_users([
            'fields' => ['ID', 'display_name', 'user_email', 'roles'],
        ]);

        $payloadUsers = [];
        foreach ($users as $user) {
            $roles = is_array($user->roles) ? $user->roles : [];
            $mappedRole = RoleMapper::mapWordPressRoles($roles);

            $payloadUsers[] = [
                'name' => (string) $user->display_name,
                'email' => (string) $user->user_email,
                'platform_user_id' => (string) $user->ID,
                'role' => $mappedRole,
            ];
        }

        $payload = [
            'domain' => home_url('/'),
            'platform' => 'wordpress',
            'platform_version' => get_bloginfo('version'),
            'timezone' => wp_timezone_string(),
            'admin_email' => get_option('admin_email'),
            'users' => $payloadUsers,
        ];

        try {
            $this->client->updateSiteRegistration($siteId, $payload);
            update_option('seoauto_last_user_sync', time(), false);
        } catch (\Throwable $exception) {
            $this->logger->warning('user_sync_failed', [
                'entity_type' => 'site',
                'entity_id' => (string) $siteId,
                'error' => $exception->getMessage(),
            ], 'outbound');
        }
    }
}
