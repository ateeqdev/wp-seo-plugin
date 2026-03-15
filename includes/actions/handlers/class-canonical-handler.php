<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\Actions\Handlers;

use Exception;
use SEOWorkerAI\Connector\SEO\InterfaceSeoAdapter;
use SEOWorkerAI\Connector\Utils\Logger;

final class CanonicalHandler extends AbstractActionHandler
{
    private InterfaceSeoAdapter $adapter;

    public function __construct(InterfaceSeoAdapter $adapter, Logger $logger)
    {
        parent::__construct($logger);
        $this->adapter = $adapter;
    }

    /**
     * @param  array<string, mixed>  $action
     * @return bool|\WP_Error
     */
    public function validate(array $action)
    {
        return $this->validatePostOrUrlTarget($action);
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    public function execute(array $action): array
    {
        $postId = $this->resolvePostId($action);
        $payload = $this->payload($action);

        $canonical = (string) ($payload['canonical_url'] ?? '');
        $canonical = esc_url_raw($canonical);

        if ($canonical === '') {
            throw new Exception('No canonical_url provided.');
        }

        if ((bool) get_option('seoworkerai_canonical_same_host', true)) {
            $siteHost = (string) wp_parse_url(home_url(), PHP_URL_HOST);
            $canonicalHost = (string) wp_parse_url($canonical, PHP_URL_HOST);

            if ($siteHost !== '' && $canonicalHost !== '' && strtolower($siteHost) !== strtolower($canonicalHost)) {
                throw new Exception('Canonical URL must use the same host.');
            }
        }

        $url = $this->resolveUrl($action);
        if ($postId === 0 && $url !== '') {
            $store = $this->getUrlMetaStore();
            $beforeCanonical = (string) $store->getMeta($url, 'canonical');

            if (trim($beforeCanonical) === trim($canonical)) {
                return [
                    'status' => 'applied',
                    'metadata' => ['noop' => true],
                    'before' => ['canonical' => $beforeCanonical],
                    'after' => ['canonical' => $beforeCanonical],
                ];
            }

            $store->setMeta($url, 'canonical', $canonical);

            return [
                'status' => 'applied',
                'metadata' => [
                    'handler' => 'canonical',
                    'adapter' => 'url_meta_store',
                ],
                'before' => ['canonical' => $beforeCanonical],
                'after' => [
                    'canonical' => $canonical,
                    'adapter' => 'url_meta_store',
                ],
            ];
        }

        $before = [
            'canonical' => (string) ($this->adapter->getCanonical($postId) ?? ''),
        ];

        if (trim($before['canonical']) === trim($canonical)) {
            return [
                'status' => 'applied',
                'metadata' => ['noop' => true],
                'before' => $before,
                'after' => $before,
            ];
        }

        if (! $this->adapter->setCanonical($postId, $canonical)) {
            throw new Exception('Adapter failed to set canonical URL.');
        }

        $after = [
            'canonical' => (string) ($this->adapter->getCanonical($postId) ?? ''),
            'adapter' => $this->adapter->getName(),
        ];

        return [
            'status' => 'applied',
            'metadata' => [
                'handler' => 'canonical',
                'adapter' => $this->adapter->getName(),
            ],
            'before' => $before,
            'after' => $after,
        ];
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    public function rollback(array $action): array
    {
        $postId = $this->resolvePostId($action);
        $rawBefore = isset($action['before_snapshot']) ? (string) $action['before_snapshot'] : '';
        $before = json_decode($rawBefore, true);

        if (! is_array($before)) {
            return ['status' => 'failed', 'error' => 'Missing before snapshot'];
        }

        $previous = isset($before['canonical']) ? (string) $before['canonical'] : '';

        $url = $this->resolveUrl($action);
        if ($postId === 0 && $url !== '') {
            $store = $this->getUrlMetaStore();
            if ($previous !== '') {
                $store->setMeta($url, 'canonical', $previous);
            } else {
                $store->deleteMeta($url, 'canonical');
            }

            return ['status' => 'rolled_back'];
        }

        if ($previous !== '') {
            $this->adapter->setCanonical($postId, $previous);
        } else {
            delete_post_meta($postId, '_seoworkerai_canonical');
            delete_post_meta($postId, '_yoast_wpseo_canonical');
            delete_post_meta($postId, '_rank_math_canonical_url');
            delete_post_meta($postId, '_aioseo_canonical_url');
        }

        return ['status' => 'rolled_back'];
    }
}
