<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\Sync;

use SEOAutomation\Connector\API\LaravelClient;
use SEOAutomation\Connector\Utils\Logger;

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
        $siteId = (int) get_option('seoauto_site_id', 0);

        if ($siteId <= 0) {
            return ['connected' => false];
        }

        try {
            $response = $this->client->health($siteId);

            if (isset($response['billing']) && is_array($response['billing'])) {
                update_option('seoauto_billing', SiteRegistrar::sanitizeBillingPayload($response['billing']), false);
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
