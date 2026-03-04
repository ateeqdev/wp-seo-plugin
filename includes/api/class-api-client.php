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
        $baseUrl = rtrim((string) SEOAUTO_LARAVEL_BASE_URL, '/');

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
            $curlFallbackResponse = $this->attemptCurlFallback($url, $args, $response);
            if ($curlFallbackResponse !== null) {
                $response = $curlFallbackResponse;
            }
        }

        if (is_wp_error($response)) {
            update_option('seoauto_api_blocked', true, false);
            update_option('seoauto_api_last_error', $response->get_error_message(), false);
            update_option('seoauto_api_last_error_at', time(), false);
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
            $message = $this->buildHttpErrorMessage($statusCode, $path, $result['body']);
            update_option('seoauto_api_blocked', $statusCode >= 500, false);
            update_option('seoauto_api_last_error', $message, false);
            update_option('seoauto_api_last_error_at', time(), false);
            $this->logger->warning('api_http_error', [
                'entity_id' => $path,
                'error' => $message,
                'response_payload' => $result['body'],
            ], 'outbound');

            throw new RuntimeException($message, $statusCode);
        }

        update_option('seoauto_api_blocked', false, false);
        update_option('seoauto_api_last_error', '', false);
        update_option('seoauto_api_last_error_at', 0, false);

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

    /**
     * @param array<string, mixed> $body
     */
    private function buildHttpErrorMessage(int $statusCode, string $path, array $body): string
    {
        $parts = [sprintf('HTTP %d for %s', $statusCode, $path)];

        $topLevelMessage = isset($body['message']) ? trim((string) $body['message']) : '';
        if ($topLevelMessage !== '') {
            $parts[] = $topLevelMessage;
        }

        if (isset($body['errors']) && is_array($body['errors'])) {
            $validationParts = [];
            foreach ($body['errors'] as $field => $messages) {
                if (!is_array($messages)) {
                    continue;
                }

                foreach ($messages as $rawMessage) {
                    $message = trim((string) $rawMessage);
                    if ($message === '') {
                        continue;
                    }

                    $validationParts[] = (string) $field . ': ' . $message;
                }
            }

            if (!empty($validationParts)) {
                $parts[] = 'Validation: ' . implode(' | ', $validationParts);
            }
        }

        return implode(' - ', $parts);
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|null
     */
    private function attemptCurlFallback(string $url, array $args, \WP_Error $initialError): ?array
    {
        if (!function_exists('curl_init') || !function_exists('curl_exec')) {
            return null;
        }

        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $expectedHost = strtolower((string) wp_parse_url((string) SEOAUTO_LARAVEL_BASE_URL, PHP_URL_HOST));
        if ($host === '' || $expectedHost === '' || $host !== $expectedHost) {
            return null;
        }

        $curl = curl_init($url);
        if ($curl === false) {
            return null;
        }

        $method = strtoupper((string) ($args['method'] ?? 'GET'));
        $body = isset($args['body']) ? (string) $args['body'] : '';
        $timeout = max(1, (int) ($args['timeout'] ?? 15));
        $sslVerify = (bool) ($args['sslverify'] ?? true);
        $headers = $this->flattenHeaders($args['headers'] ?? []);
        $responseHeaders = [];

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, $sslVerify);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, $sslVerify ? 2 : 0);
        curl_setopt(
            $curl,
            CURLOPT_HEADERFUNCTION,
            static function ($handle, string $headerLine) use (&$responseHeaders): int {
                $length = strlen($headerLine);
                $line = trim($headerLine);
                if ($line === '' || strpos($line, ':') === false) {
                    return $length;
                }

                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[trim($name)] = trim($value);

                return $length;
            }
        );

        if ($body !== '' && in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $rawBody = curl_exec($curl);
        $curlError = curl_error($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if (!is_string($rawBody)) {
            $rawBody = '';
        }

        if ($curlError !== '') {
            $this->logger->warning('api_curl_fallback_failed', [
                'entity_type' => 'url',
                'entity_id' => $url,
                'error' => $curlError,
                'initial_error' => $initialError->get_error_message(),
            ], 'outbound');

            return null;
        }

        $this->logger->warning('api_curl_fallback_used', [
            'entity_type' => 'url',
            'entity_id' => $url,
            'status_code' => $statusCode,
            'initial_error' => $initialError->get_error_message(),
        ], 'outbound');

        return [
            'headers' => $responseHeaders,
            'body' => $rawBody,
            'response' => [
                'code' => $statusCode,
                'message' => '',
            ],
            'cookies' => [],
            'filename' => null,
        ];
    }

    /**
     * @param mixed $headers
     * @return array<int, string>
     */
    private function flattenHeaders($headers): array
    {
        if (!is_array($headers)) {
            return [];
        }

        $result = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || $name === '') {
                continue;
            }

            if (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }

            $result[] = $name . ': ' . (string) $value;
        }

        return $result;
    }
}
