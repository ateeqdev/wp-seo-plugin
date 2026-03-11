<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\Events;

use SEOWorkerAI\Connector\API\LaravelClient;
use SEOWorkerAI\Connector\Utils\JsonHelper;
use SEOWorkerAI\Connector\Utils\Logger;

final class EventDispatcher
{
    private LaravelClient $client;

    private EventOutbox $outbox;

    private Logger $logger;

    public function __construct(LaravelClient $client, EventOutbox $outbox, Logger $logger)
    {
        $this->client = $client;
        $this->outbox = $outbox;
        $this->logger = $logger;
    }

    public function flushQueuedEvents(): void
    {
        $siteId = (int) get_option('seoworkerai_site_id', 0);

        if ($siteId <= 0) {
            return;
        }

        $billing = get_option('seoworkerai_billing', []);
        if (is_array($billing) && !empty($billing['payment_required'])) {
            return;
        }

        $rows = $this->outbox->listDispatchable(25);

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $attempts = (int) ($row['attempts'] ?? 0) + 1;
            $payload = JsonHelper::decodeArray((string) ($row['payload'] ?? ''));

            try {
                $this->client->sendEvent($siteId, $payload);
                $this->outbox->markSent($id);
                continue;
            } catch (\Throwable $exception) {
                $finalFail = $attempts >= 5;
                $delay = $this->backoffDelay($attempts);
                $this->outbox->markRetry($id, $attempts, $delay, $exception->getMessage(), $finalFail);
                $this->logger->warning('event_dispatch_failed', [
                    'entity_type' => 'event',
                    'entity_id' => (string) $id,
                    'error' => $exception->getMessage(),
                    'request_payload' => $payload,
                ], 'outbound');
            }
        }
    }

    private function backoffDelay(int $attempt): int
    {
        $schedule = [2, 5, 15, 30, 60];
        $base = $schedule[$attempt - 1] ?? 60;

        return $base + random_int(0, 2);
    }
}
