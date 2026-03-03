<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\Actions;

use SEOAutomation\Connector\API\LaravelClient;
use SEOAutomation\Connector\Utils\Logger;

final class ActionPoller
{
    private LaravelClient $client;

    private ActionReceiver $receiver;

    private Logger $logger;

    public function __construct(LaravelClient $client, ActionReceiver $receiver, Logger $logger)
    {
        $this->client = $client;
        $this->receiver = $receiver;
        $this->logger = $logger;
    }

    public function poll(): void
    {
        $siteId = (int) get_option('seoauto_site_id', 0);

        if ($siteId <= 0) {
            return;
        }

        try {
            $query = ['limit' => 20];

            $response = $this->client->fetchPendingActions($siteId, $query);

            $actions = isset($response['actions']) && is_array($response['actions']) ? $response['actions'] : [];

            foreach ($actions as $action) {
                if (is_array($action)) {
                    $this->receiver->receivePulledAction($action);
                }
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('action_poll_failed', [
                'error' => $exception->getMessage(),
                'entity_type' => 'site',
                'entity_id' => (string) $siteId,
            ], 'outbound');
        }
    }
}
