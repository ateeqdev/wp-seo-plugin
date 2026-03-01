<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\API;

use RuntimeException;
use SEOAutomation\Connector\Auth\SiteTokenManager;
use SEOAutomation\Connector\Utils\Logger;

final class ApiClient
{
    private SiteTokenManager $tokenManager;

    private Logger $logger;

    public function __construct(SiteTokenManager $tokenManager, Logger $logger)
    {
        $this->tokenManager = $tokenManager;
        $this->logger = $logger;
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function request(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        array $headers = [],
        bool $includeToken = true,
        int $timeout = 15
    ): array {
        $baseUrl = rtrim((string) get_option('seoauto_base_url', ''), '/');

        if ($baseUrl === '') {
            throw new RuntimeException('seoauto_base_url is not configured.');
        }

        $url = $baseUrl . '/' . ltrim($path, '/');
        if (!empty($query)) {
            $url = add_query_arg($query, $url);
        }

        $requestHeaders = array_merge(
            [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            $headers
        );

        if ($includeToken) {
            $token = $this->tokenManager->getToken();
            if ($token === null) {
                throw new RuntimeException('Site token is not configured.');
            }

            $requestHeaders['X-Site-Token'] = $token;
        }

        $args = [
            'method' => strtoupper($method),
            'timeout' => $timeout,
            'headers' => $requestHeaders,
        ];

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $this->logger->warning('api_transport_error', [
                'entity_id' => $path,
                'error' => $response->get_error_message(),
                'request_payload' => $body,
            ], 'outbound');

            throw new RuntimeException($response->get_error_message(), 0);
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $rawBody = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($rawBody, true);

        $result = [
            'status_code' => $statusCode,
            'headers' => wp_remote_retrieve_headers($response),
            'body' => is_array($decoded) ? $decoded : ['raw' => $rawBody],
        ];

        if ($statusCode >= 400) {
            $message = sprintf('HTTP %d for %s', $statusCode, $path);
            $this->logger->warning('api_http_error', [
                'entity_id' => $path,
                'error' => $message,
                'response_payload' => $result['body'],
            ], 'outbound');

            throw new RuntimeException($message, $statusCode);
        }

        return $result;
    }
}
