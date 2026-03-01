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
            'sslverify' => true,
        ];

        $allowInsecureSsl = (bool) get_option('seoauto_allow_insecure_ssl', false);
        $autoAllowInsecureSsl = $this->shouldAutoDisableSslVerifyForBaseUrl($baseUrl);
        $args['sslverify'] = !($allowInsecureSsl || $autoAllowInsecureSsl);

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response) && $this->isSslCertificateError($response)) {
            // Local development fallback: if cert chain is untrusted but host resolves locally,
            // retry once without certificate verification.
            if ($autoAllowInsecureSsl && (($args['sslverify'] ?? true) === true)) {
                $this->logger->warning('api_sslverify_fallback', [
                    'entity_id' => $path,
                    'error' => $response->get_error_message(),
                    'base_url' => $baseUrl,
                ], 'outbound');

                $args['sslverify'] = false;
                $response = wp_remote_request($url, $args);
            }
        }

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

    private function isSslCertificateError(\WP_Error $error): bool
    {
        return stripos($error->get_error_message(), 'cURL error 60') !== false
            || stripos($error->get_error_message(), 'SSL certificate') !== false;
    }

    private function shouldAutoDisableSslVerifyForBaseUrl(string $baseUrl): bool
    {
        $host = wp_parse_url($baseUrl, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }

        $hostLower = strtolower($host);
        if (in_array($hostLower, ['localhost'], true)) {
            return true;
        }

        if (preg_match('/\.(test|local|localhost|invalid)$/i', $hostLower) === 1) {
            return true;
        }

        if ($this->isPrivateOrReservedIp($hostLower)) {
            return true;
        }

        $resolved = gethostbyname($hostLower);
        if ($resolved !== $hostLower && $this->isPrivateOrReservedIp($resolved)) {
            return true;
        }

        return false;
    }

    private function isPrivateOrReservedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if ($ip === '::1') {
            return true;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
