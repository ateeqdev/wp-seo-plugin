<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\Sync;

use SEOWorkerAI\Connector\API\LaravelClient;
use SEOWorkerAI\Connector\Auth\OwnershipProofStore;
use SEOWorkerAI\Connector\Auth\SiteTokenManager;
use SEOWorkerAI\Connector\Utils\Logger;

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
        $siteId = (int) get_option('seoworkerai_site_id', 0);
        $token = $this->tokenManager->getToken();

        $payload = [
            'domain' => home_url('/'),
            'platform' => 'wordpress',
            'platform_version' => get_bloginfo('version'),
            'timezone' => wp_timezone_string(),
            'admin_email' => get_option('admin_email'),
            'users' => $this->collectUsers(),
        ];
        $payload = array_merge($payload, $this->collectSiteProfile());

        if ($token !== null && $token !== '') {
            $payload['api_key'] = $token;
        }

        try {
            if ($siteId > 0 && $this->tokenManager->hasToken()) {
                try {
                    $this->reactivateSiteWithOwnershipProof($siteId, $payload);
                    $response = $fast
                        ? $this->client->updateSiteRegistrationFast($siteId, $payload)
                        : $this->client->updateSiteRegistration($siteId, $payload);
                } catch (\Throwable $exception) {
                    if (! $this->shouldRetryAsFreshRegistration($exception)) {
                        throw $exception;
                    }

                    $this->logger->warning('site_registration_stale_identity_detected', [
                        'site_id' => $siteId,
                        'http_code' => (int) $exception->getCode(),
                        'error' => $exception->getMessage(),
                    ], 'outbound');

                    $this->resetLocalSiteIdentity();
                    unset($payload['api_key']);
                    $response = $this->registerFreshSite($payload, $fast);
                }
            } else {
                $response = $this->registerFreshSite($payload, $fast);
            }

            $this->syncLocalSiteProfileFromResponse($response);
            $this->maybeTriggerInitialAudit();

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
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function registerFreshSite(array $payload, bool $fast): array
    {
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

        try {
            $response = $fast
                ? $this->client->registerSiteFast($payload)
                : $this->client->registerSite($payload);

            if (!empty($response['site_id'])) {
                update_option('seoworkerai_site_id', (int) $response['site_id'], false);
            }
            if (!empty($response['api_key'])) {
                $this->tokenManager->storeToken((string) $response['api_key']);
            }

            return $response;
        } finally {
            OwnershipProofStore::delete($challengeId);
        }
    }

    private function resetLocalSiteIdentity(): void
    {
        update_option('seoworkerai_site_id', 0, false);
        $this->tokenManager->clearToken();
    }

    private function shouldRetryAsFreshRegistration(\Throwable $exception): bool
    {
        return in_array((int) $exception->getCode(), [401, 404, 409], true);
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
            $roles = [];
            if (isset($user->roles) && is_array($user->roles)) {
                $roles = $user->roles;
            } else {
                $wpUser = get_userdata((int) $user->ID);
                if ($wpUser instanceof \WP_User) {
                    $roles = is_array($wpUser->roles) ? $wpUser->roles : [];
                }
            }
            $mappedRole = RoleMapper::mapWordPressRoles($roles);

            $mapped[] = [
                'name' => (string) $user->display_name,
                'email' => (string) $user->user_email,
                'platform_user_id' => (string) $user->ID,
                'role' => $mappedRole,
                'avatar' => $this->resolveUserAvatarUrl((int) $user->ID),
            ];
        }

        return $mapped;
    }

    private function resolveUserAvatarUrl(int $userId): string
    {
        $avatarUrl = get_avatar_url($userId, ['size' => 256]);
        if (!is_string($avatarUrl) || trim($avatarUrl) === '') {
            return '';
        }

        return esc_url_raw($avatarUrl);
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
        $base = home_url('/wp-json/seoworkerai/v1/ownership-proof');

        return add_query_arg(['challenge_id' => $challengeId], $base);
    }

    /**
     * @return array{description:string,taste:string,locations:array<int,array<string,int|string>>}
     */
    private function collectSiteProfile(): array
    {
        $siteName = trim((string) get_bloginfo('name'));
        $tagline = trim((string) get_bloginfo('description'));
        $host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        $host = is_string($host) ? trim($host) : '';

        $description = trim((string) get_option('seoworkerai_site_profile_description', ''));
        if ($description === '') {
            if ($tagline !== '') {
                $description = $tagline;
            } elseif ($siteName !== '') {
                $description = "{$siteName} website content and resources.";
            } elseif ($host !== '') {
                $description = "Website content and resources for {$host}.";
            } else {
                $description = 'Website content and resources.';
            }
        }

        $taste = trim((string) get_option('seoworkerai_site_profile_taste', ''));
        if ($taste === '') {
            $taste = 'Use a clear, factual, SEO-first writing style: concise headings, plain language, and actionable recommendations.';
        }

        $locations = $this->normalizeLocationsOption(get_option('seoworkerai_site_locations', []));
        if ($locations === []) {
            $locations = [[
                'location_type' => 'primary',
                'location_code' => (int) get_option('seoworkerai_site_location_code', 2840),
                'location_name' => trim((string) get_option('seoworkerai_site_location_name', 'United States')) ?: 'United States',
                'priority' => 0,
            ]];
        }

        return [
            'description' => $description,
            'taste' => $taste,
            'locations' => $locations,
        ];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function syncLocalSiteProfileFromResponse(array $response): void
    {
        $description = trim((string) ($response['description'] ?? ''));
        $taste = trim((string) ($response['taste'] ?? ''));

        if ($description !== '') {
            update_option('seoworkerai_site_profile_description', $description, false);
        }

        if ($taste !== '') {
            update_option('seoworkerai_site_profile_taste', $taste, false);
        }

        $locations = isset($response['locations']) && is_array($response['locations']) ? $response['locations'] : [];
        if (!empty($locations) && is_array($locations[0])) {
            $normalizedLocations = $this->normalizeLocationsOption($locations);
            $primaryLocation = $normalizedLocations[0];
            update_option('seoworkerai_site_locations', $normalizedLocations, false);
            update_option('seoworkerai_site_location_code', (int) ($primaryLocation['location_code'] ?? 2840), false);
            update_option('seoworkerai_site_location_name', sanitize_text_field((string) ($primaryLocation['location_name'] ?? 'United States')), false);
        }

        if (isset($response['site_settings']) && is_array($response['site_settings'])) {
            update_option('seoworkerai_site_seo_settings', $this->sanitizeSiteSettingsPayload($response['site_settings']), false);
        } elseif (array_key_exists('domain_rating', $response)) {
            $settings = get_option('seoworkerai_site_seo_settings', []);
            if (!is_array($settings)) {
                $settings = [];
            }

            $settings['domain_rating'] = $response['domain_rating'] !== null ? (int) $response['domain_rating'] : null;
            $settings['domain_rating_checked_at'] = isset($response['domain_rating_checked_at']) ? sanitize_text_field((string) $response['domain_rating_checked_at']) : '';

            update_option('seoworkerai_site_seo_settings', $settings, false);
        }

        if (isset($response['billing']) && is_array($response['billing'])) {
            update_option('seoworkerai_billing', self::sanitizeBillingPayload($response['billing']), false);
        }

        if (isset($response['initial_site_audit']) && is_array($response['initial_site_audit'])) {
            $this->syncInitialAuditFromResponse($response['initial_site_audit']);
        }
    }

    private function maybeTriggerInitialAudit(): void
    {
        $siteId = (int) get_option('seoworkerai_site_id', 0);
        if ($siteId <= 0) {
            return;
        }

        $requested = (bool) get_option('seoworkerai_initial_audit_requested', false);
        $status = (string) get_option('seoworkerai_initial_audit_status', 'pending');

        if ($requested || in_array($status, ['queued', 'in_progress', 'completed', 'already_started', 'already_completed'], true)) {
            return;
        }

        try {
            $response = $this->client->triggerInitialAudit($siteId, [
                'trigger' => 'plugin_install',
            ]);

            $this->syncInitialAuditFromResponse($response);
            update_option('seoworkerai_initial_audit_requested', true, false);

            if (isset($response['billing']) && is_array($response['billing'])) {
                update_option('seoworkerai_billing', self::sanitizeBillingPayload($response['billing']), false);
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('initial_audit_request_failed', [
                'error' => $exception->getMessage(),
                'site_id' => (string) $siteId,
            ], 'outbound');
        }
    }

    /**
     * @param array<string, mixed> $response
     */
    private function syncInitialAuditFromResponse(array $response): void
    {
        $payload = self::sanitizeInitialAuditPayload($response);

        update_option('seoworkerai_initial_audit_status', $payload['status'], false);
        update_option('seoworkerai_initial_audit_message', $payload['message'], false);

        if ($payload['started_at'] > 0) {
            update_option('seoworkerai_initial_audit_started_at', $payload['started_at'], false);
        }
        if ($payload['completed_at'] > 0) {
            update_option('seoworkerai_initial_audit_completed_at', $payload['completed_at'], false);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status:string,message:string,started_at:int,completed_at:int}
     */
    public static function sanitizeInitialAuditPayload(array $payload): array
    {
        return [
            'status' => sanitize_text_field((string) ($payload['status'] ?? 'pending')),
            'message' => sanitize_text_field((string) ($payload['message'] ?? '')),
            'started_at' => strtotime((string) ($payload['started_at'] ?? '')) ?: 0,
            'completed_at' => strtotime((string) ($payload['completed_at'] ?? '')) ?: 0,
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function sanitizeSiteSettingsPayload(array $settings): array
    {
        return [
            'template_id' => isset($settings['template_id']) ? (int) $settings['template_id'] : 0,
            'template_name' => sanitize_text_field((string) ($settings['template_name'] ?? '')),
            'provider_name' => sanitize_text_field((string) ($settings['provider_name'] ?? 'dataforseo')),
            'domain_rating' => isset($settings['domain_rating']) ? (int) $settings['domain_rating'] : null,
            'domain_rating_checked_at' => sanitize_text_field((string) ($settings['domain_rating_checked_at'] ?? '')),
            'min_search_volume' => isset($settings['min_search_volume']) ? (int) $settings['min_search_volume'] : 0,
            'max_search_volume' => ($settings['max_search_volume'] ?? null) !== null ? (int) $settings['max_search_volume'] : null,
            'max_keyword_difficulty' => isset($settings['max_keyword_difficulty']) ? (int) $settings['max_keyword_difficulty'] : 100,
            'preferred_keyword_type' => sanitize_text_field((string) ($settings['preferred_keyword_type'] ?? '')),
            'content_briefs_per_run' => isset($settings['content_briefs_per_run']) ? (int) $settings['content_briefs_per_run'] : 3,
            'prefer_low_difficulty' => !empty($settings['prefer_low_difficulty']),
            'allow_low_volume' => !empty($settings['allow_low_volume']),
            'brand_twitter_handle' => sanitize_text_field((string) ($settings['brand_twitter_handle'] ?? '')),
            'default_social_image_url' => esc_url_raw((string) ($settings['default_social_image_url'] ?? '')),
            'selection_notes' => sanitize_textarea_field((string) ($settings['selection_notes'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $billing
     * @return array<string, mixed>
     */
    public static function sanitizeBillingPayload(array $billing): array
    {
        return [
            'status' => sanitize_text_field((string) ($billing['status'] ?? 'payment_required')),
            'payment_required' => !empty($billing['payment_required']),
            'quota_blocked' => !empty($billing['quota_blocked']),
            'quota_message' => sanitize_text_field((string) ($billing['quota_message'] ?? '')),
            'company_id' => isset($billing['company_id']) ? (int) $billing['company_id'] : 0,
            'company_name' => sanitize_text_field((string) ($billing['company_name'] ?? '')),
            'plan_name' => sanitize_text_field((string) ($billing['plan_name'] ?? '')),
            'plan_slug' => sanitize_text_field((string) ($billing['plan_slug'] ?? '')),
            'plan_price' => isset($billing['plan_price']) ? (float) $billing['plan_price'] : 0.0,
            'plan_interval' => sanitize_text_field((string) ($billing['plan_interval'] ?? 'monthly')),
            'billing_starts_at' => sanitize_text_field((string) ($billing['billing_starts_at'] ?? '')),
            'billing_expires_at' => sanitize_text_field((string) ($billing['billing_expires_at'] ?? '')),
            'payment_url' => esc_url_raw((string) ($billing['payment_url'] ?? '')),
            'quota_limits' => isset($billing['quota_limits']) && is_array($billing['quota_limits']) ? $billing['quota_limits'] : [],
            'quota_usage' => isset($billing['quota_usage']) && is_array($billing['quota_usage']) ? $billing['quota_usage'] : [],
        ];
    }

    /**
     * @param mixed $locations
     * @return array<int, array{location_type:string,location_code:int,location_name:string,priority:int}>
     */
    public function normalizeLocationsOption($locations): array
    {
        if (!is_array($locations)) {
            return [];
        }

        $rows = [];
        foreach ($locations as $index => $location) {
            if (!is_array($location)) {
                continue;
            }

            $locationCode = isset($location['location_code']) ? (int) $location['location_code'] : 0;
            $locationName = sanitize_text_field((string) ($location['location_name'] ?? ''));
            $locationType = sanitize_text_field((string) ($location['location_type'] ?? 'secondary'));

            if ($locationCode <= 0 || $locationName === '') {
                continue;
            }

            $rows[] = [
                'location_type' => $locationType === 'primary' ? 'primary' : 'secondary',
                'location_code' => $locationCode,
                'location_name' => $locationName,
                'priority' => (int) ($location['priority'] ?? $index),
            ];
        }

        usort($rows, static fn (array $left, array $right): int => $left['priority'] <=> $right['priority']);

        $seenPrimary = false;
        foreach ($rows as $index => $row) {
            if ($row['location_type'] === 'primary') {
                if ($seenPrimary) {
                    $rows[$index]['location_type'] = 'secondary';
                }
                $seenPrimary = true;
            }
            $rows[$index]['priority'] = $index;
        }

        if ($rows !== [] && !$seenPrimary) {
            $rows[0]['location_type'] = 'primary';
        }

        return array_values($rows);
    }
}
