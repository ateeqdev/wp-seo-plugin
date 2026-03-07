<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\Actions\Handlers;

use Exception;
use SEOWorkerAI\Connector\Utils\Logger;

final class RedirectHandler extends AbstractActionHandler
{
    public function __construct(Logger $logger)
    {
        parent::__construct($logger);
    }

    /**
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    public function execute(array $action): array
    {
        $payload = $this->payload($action);
        $redirects = [];

        if (isset($payload['redirects']) && is_array($payload['redirects'])) {
            $redirects = $payload['redirects'];
        } elseif (isset($payload['source_url'], $payload['target_url'])) {
            $redirects = [$payload];
        }

        if (empty($redirects)) {
            throw new Exception('No redirects provided.');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'seoworkerai_redirects';

        $applied = [];

        foreach ($redirects as $redirect) {
            if (!is_array($redirect)) {
                continue;
            }

            $source = $this->normalizeUrl((string) ($redirect['source_url'] ?? ''));
            $target = $this->normalizeUrl((string) ($redirect['target_url'] ?? ''));
            $type = (int) ($redirect['redirect_type'] ?? 301);
            $type = $type === 302 ? 302 : 301;

            if ($source === '' || $target === '' || $source === $target) {
                continue;
            }

            $wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $table,
                [
                    'source_url' => $source,
                    'target_url' => $target,
                    'redirect_type' => $type,
                    'created_at' => current_time('mysql'),
                ],
                ['%s', '%s', '%d', '%s']
            );

            $applied[] = [
                'source_url' => $source,
                'target_url' => $target,
                'redirect_type' => $type,
            ];
        }

        if (empty($applied)) {
            return [
                'status' => 'applied',
                'metadata' => ['noop' => true, 'reason' => 'No valid redirects'],
                'before' => [],
                'after' => [],
            ];
        }

        return [
            'status' => 'applied',
            'metadata' => [
                'handler' => 'redirect',
                'redirects_applied' => count($applied),
            ],
            'before' => [],
            'after' => ['redirects' => $applied],
        ];
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        return rtrim(esc_url_raw($url), '/');
    }
}
