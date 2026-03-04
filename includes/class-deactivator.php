<?php

declare(strict_types=1);

namespace SEOAutomation\Connector;

use SEOAutomation\Connector\API\ApiClient;
use SEOAutomation\Connector\API\LaravelClient;
use SEOAutomation\Connector\API\RetryPolicy;
use SEOAutomation\Connector\Auth\OwnershipProofStore;
use SEOAutomation\Connector\Auth\SiteTokenManager;
use SEOAutomation\Connector\Auth\TokenEncryption;
use SEOAutomation\Connector\Queue\QueueManager;
use SEOAutomation\Connector\Utils\Logger;

final class Deactivator
{
    public static function deactivate(): void
    {
        self::attemptVerifiedSiteDeactivation();
        QueueManager::unscheduleRecurringJobs();
        $timestamp = wp_next_scheduled('seoauto_auto_register_site');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'seoauto_auto_register_site');
        }
        flush_rewrite_rules();
    }

    private static function attemptVerifiedSiteDeactivation(): void
    {
        $siteId = (int) get_option('seoauto_site_id', 0);
        if ($siteId <= 0) {
            return;
        }

        try {
            $logger = new Logger();
            $tokenManager = new SiteTokenManager(new TokenEncryption());
            if (!$tokenManager->hasToken()) {
                return;
            }

            $client = new LaravelClient(
                new ApiClient($tokenManager, $logger),
                new RetryPolicy(),
                $logger
            );

            $challenge = $client->createSiteOwnershipChallenge($siteId, ['intent' => 'deactivate']);
            $challengeId = (string) ($challenge['challenge_id'] ?? '');
            $challengeToken = (string) ($challenge['challenge_token'] ?? '');
            $expiresAt = strtotime((string) ($challenge['expires_at'] ?? '')) ?: (time() + 600);
            if ($challengeId === '' || $challengeToken === '') {
                return;
            }

            OwnershipProofStore::put($challengeId, $challengeToken, $expiresAt);
            $proofUrl = add_query_arg(
                ['challenge_id' => $challengeId],
                home_url('/wp-json/seoauto/v1/ownership-proof')
            );
            $client->deactivateSite($siteId, [
                'ownership_challenge_id' => $challengeId,
                'ownership_proof_url' => $proofUrl,
            ]);
            OwnershipProofStore::delete($challengeId);
        } catch (\Throwable) {
            // Deactivation should never be blocked by remote/API errors.
        }
    }
}
