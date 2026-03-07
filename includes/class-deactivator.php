<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector;

use SEOWorkerAI\Connector\API\ApiClient;
use SEOWorkerAI\Connector\API\LaravelClient;
use SEOWorkerAI\Connector\API\RetryPolicy;
use SEOWorkerAI\Connector\Auth\OwnershipProofStore;
use SEOWorkerAI\Connector\Auth\SiteTokenManager;
use SEOWorkerAI\Connector\Auth\TokenEncryption;
use SEOWorkerAI\Connector\Queue\QueueManager;
use SEOWorkerAI\Connector\Utils\Logger;

final class Deactivator
{
    public static function deactivate(): void
    {
        self::attemptVerifiedSiteDeactivation();
        QueueManager::unscheduleRecurringJobs();
        $timestamp = wp_next_scheduled('seoworkerai_auto_register_site');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'seoworkerai_auto_register_site');
        }
        flush_rewrite_rules();
    }

    private static function attemptVerifiedSiteDeactivation(): void
    {
        $siteId = (int) get_option('seoworkerai_site_id', 0);
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
                home_url('/wp-json/seoworkerai/v1/ownership-proof')
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
