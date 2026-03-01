<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\API;

use SEOAutomation\Connector\Utils\Logger;

final class LaravelClient
{
    private ApiClient $apiClient;

    private RetryPolicy $retryPolicy;

    private Logger $logger;

    public function __construct(ApiClient $apiClient, RetryPolicy $retryPolicy, Logger $logger)
    {
        $this->apiClient = $apiClient;
        $this->retryPolicy = $retryPolicy;
        $this->logger = $logger;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function registerSite(array $payload): array
    {
        $response = $this->retryPolicy->execute(function () use ($payload): array {
            return $this->apiClient->request('POST', '/api/sites/register', [], $payload, [], false, 20);
        });

        return (array) $response['body'];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateSiteRegistration(int $siteId, array $payload): array
    {
        $response = $this->retryPolicy->execute(function () use ($siteId, $payload): array {
            return $this->apiClient->request('POST', '/api/sites/' . $siteId . '/register', [], $payload, [], true, 20);
        });

        return (array) $response['body'];
    }

    /**
     * @return array<string, mixed>
     */
    public function verifySite(): array
    {
        $response = $this->retryPolicy->execute(function (): array {
            return $this->apiClient->request('POST', '/api/sites/verify', [], null, [], true, 15);
        });

        return (array) $response['body'];
    }

    /**
     * @return array<string, mixed>
     */
    public function health(int $siteId): array
    {
        $response = $this->retryPolicy->execute(function () use ($siteId): array {
            return $this->apiClient->request('GET', '/api/sites/' . $siteId . '/health', [], null, [], true, 15);
        });

        return (array) $response['body'];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sendEvent(int $siteId, array $payload): array
    {
        $response = $this->retryPolicy->execute(function () use ($siteId, $payload): array {
            return $this->apiClient->request('POST', '/api/sites/' . $siteId . '/events', [], $payload, [], true, 20);
        });

        return (array) $response['body'];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function fetchPendingActions(int $siteId, array $query = []): array
    {
        $response = $this->retryPolicy->execute(function () use ($siteId, $query): array {
            return $this->apiClient->request('GET', '/api/sites/' . $siteId . '/actions/pending', $query, null, [], true, 20);
        });

        return (array) $response['body'];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function reportActionStatus(int $siteId, int $actionId, array $payload): array
    {
        $response = $this->retryPolicy->execute(function () use ($siteId, $actionId, $payload): array {
            return $this->apiClient->request(
                'POST',
                '/api/sites/' . $siteId . '/actions/' . $actionId . '/status',
                [],
                $payload,
                [],
                true,
                20
            );
        });

        return (array) $response['body'];
    }

    /**
     * @return array<string, mixed>
     */
    public function listTasks(): array
    {
        $response = $this->retryPolicy->execute(function (): array {
            return $this->apiClient->request('GET', '/api/seo/tasks');
        });

        return (array) $response['body'];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listScheduledTasks(array $query = []): array
    {
        $response = $this->retryPolicy->execute(function () use ($query): array {
            return $this->apiClient->request('GET', '/api/seo/scheduled-tasks', $query);
        });

        return (array) $response['body'];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listExecutionLogs(array $query = []): array
    {
        $response = $this->retryPolicy->execute(function () use ($query): array {
            return $this->apiClient->request('GET', '/api/seo/execution-logs', $query);
        });

        return (array) $response['body'];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listContentBriefs(int $siteId, array $query = []): array
    {
        $response = $this->retryPolicy->execute(function () use ($siteId, $query): array {
            return $this->apiClient->request('GET', '/api/sites/' . $siteId . '/content-briefs', $query);
        });

        return (array) $response['body'];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function linkArticleToBrief(int $siteId, int $briefId, array $payload): array
    {
        $response = $this->retryPolicy->execute(function () use ($siteId, $briefId, $payload): array {
            return $this->apiClient->request(
                'POST',
                '/api/sites/' . $siteId . '/content-briefs/' . $briefId . '/link-article',
                [],
                $payload,
                [],
                true,
                20
            );
        });

        return (array) $response['body'];
    }

    /**
     * @param array<string, mixed> $context
     */
    public function logApiError(string $eventName, array $context): void
    {
        $this->logger->error($eventName, $context, 'outbound');
    }
}
