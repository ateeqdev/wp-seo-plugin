<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\Sync;

use SEOAutomation\Connector\API\LaravelClient;
use SEOAutomation\Connector\Auth\OwnershipProofStore;
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
    public function registerOrUpdate(bool $fast = false): array
    {
        $siteId = (int) get_option('seoauto_site_id', 0);
        $token = $this->tokenManager->getToken();

        $payload = [
            'domain' => home_url('/'),
            'platform' => 'wordpress',
            'platform_version' => get_bloginfo('version'),
            'timezone' => wp_timezone_string(),
            'admin_email' => get_option('admin_email'),
            'users' => $this->collectUsers(),
        ];

        if ($token !== null && $token !== '') {
            $payload['api_key'] = $token;
        }

        try {
            if ($siteId > 0 && $this->tokenManager->hasToken()) {
                $this->reactivateSiteWithOwnershipProof($siteId, $payload);
                $response = $fast
                    ? $this->client->updateSiteRegistrationFast($siteId, $payload)
                    : $this->client->updateSiteRegistration($siteId, $payload);
            } else {
                $challenge = $this->client->createOwnershipChallenge([
                    'domain' => (string) $payload['domain'],
                    'intent' => 'register',
                ]);
                $challengeId = (string) ($challenge['challenge_id'] ?? '');
                $challengeToken = (string) ($challenge['challenge_token'] ?? '');
                $expiresAt = strtotime((string) ($challenge['expires_at'] ?? '')) ?: (time() + 600);
                if ($challengeId === '' || $challengeToken === '') {
                    throw new \RuntimeException('Failed to issue ownership challenge.');
                }
                OwnershipProofStore::put($challengeId, $challengeToken, $expiresAt);
                $payload['ownership_challenge_id'] = $challengeId;
                $payload['ownership_proof_url'] = $this->buildOwnershipProofUrl($challengeId);

                $response = $fast
                    ? $this->client->registerSiteFast($payload)
                    : $this->client->registerSite($payload);
                if (!empty($response['site_id'])) {
                    update_option('seoauto_site_id', (int) $response['site_id'], false);
                }
                if (!empty($response['api_key'])) {
                    $this->tokenManager->storeToken((string) $response['api_key']);
                }
                OwnershipProofStore::delete($challengeId);
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
        $ownerAssigned = false;

        foreach ($users as $user) {
            $roles = is_array($user->roles) ? $user->roles : [];
            $mappedRole = RoleMapper::mapWordPressRole((string) ($roles[0] ?? ''));

            if (!$ownerAssigned && $mappedRole === RoleMapper::ADMIN) {
                $mappedRole = RoleMapper::OWNER;
                $ownerAssigned = true;
            }

            $mapped[] = [
                'name' => (string) $user->display_name,
                'email' => (string) $user->user_email,
                'platform_user_id' => (string) $user->ID,
                'role' => $mappedRole,
            ];
        }

        if (!$ownerAssigned && !empty($mapped)) {
            $mapped[0]['role'] = RoleMapper::OWNER;
        }

        return $mapped;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function reactivateSiteWithOwnershipProof(int $siteId, array $payload): void
    {
        try {
            $challenge = $this->client->createSiteOwnershipChallenge($siteId, ['intent' => 'reactivate']);
            $challengeId = (string) ($challenge['challenge_id'] ?? '');
            $challengeToken = (string) ($challenge['challenge_token'] ?? '');
            $expiresAt = strtotime((string) ($challenge['expires_at'] ?? '')) ?: (time() + 600);
            if ($challengeId === '' || $challengeToken === '') {
                return;
            }

            OwnershipProofStore::put($challengeId, $challengeToken, $expiresAt);
            $this->client->reactivateSite($siteId, [
                'ownership_challenge_id' => $challengeId,
                'ownership_proof_url' => $this->buildOwnershipProofUrl($challengeId),
            ]);
            OwnershipProofStore::delete($challengeId);
        } catch (\Throwable $exception) {
            $this->logger->warning('site_reactivate_with_ownership_proof_failed', [
                'error' => $exception->getMessage(),
                'site_id' => $siteId,
                'domain' => $payload['domain'] ?? '',
            ], 'outbound');
        }
    }

    private function buildOwnershipProofUrl(string $challengeId): string
    {
        $base = home_url('/wp-json/seoauto/v1/ownership-proof');

        return add_query_arg(['challenge_id' => $challengeId], $base);
    }
}
