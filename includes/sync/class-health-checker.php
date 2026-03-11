<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\Sync;

use SEOWorkerAI\Connector\API\LaravelClient;
use SEOWorkerAI\Connector\Utils\Logger;

final class HealthChecker
{
    private LaravelClient $client;

    private Logger $logger;

    public function __construct(LaravelClient $client, Logger $logger)
    {
        $this->client = $client;
        $this->logger = $logger;
    }

    /**
     * @return array<string, mixed>
     */
    public function check(): array
    {
        $siteId = (int) get_option('seoworkerai_site_id', 0);

        if ($siteId <= 0) {
            return ['connected' => false];
        }

        try {
            $response = $this->client->health($siteId);

            if (isset($response['billing']) && is_array($response['billing'])) {
                update_option('seoworkerai_billing', SiteRegistrar::sanitizeBillingPayload($response['billing']), false);
            }
            if (isset($response['initial_site_audit']) && is_array($response['initial_site_audit'])) {
                $initialAudit = SiteRegistrar::sanitizeInitialAuditPayload($response['initial_site_audit']);
                update_option('seoworkerai_initial_audit_status', $initialAudit['status'], false);
                update_option('seoworkerai_initial_audit_message', $initialAudit['message'], false);
                if ($initialAudit['started_at'] > 0) {
                    update_option('seoworkerai_initial_audit_started_at', $initialAudit['started_at'], false);
                }
                if ($initialAudit['completed_at'] > 0) {
                    update_option('seoworkerai_initial_audit_completed_at', $initialAudit['completed_at'], false);
                }
            }

            return $response;
        } catch (\Throwable $exception) {
            $this->logger->warning('health_check_failed', [
                'entity_type' => 'site',
                'entity_id' => (string) $siteId,
                'error' => $exception->getMessage(),
            ], 'outbound');

            return [
                'connected' => false,
                'error' => $exception->getMessage(),
            ];
        }
    }
}
