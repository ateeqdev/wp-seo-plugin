<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\Actions\Handlers;

use Exception;
use SEOWorkerAI\Connector\SEO\InterfaceSeoAdapter;
use SEOWorkerAI\Connector\Utils\Logger;

final class TechnicalFlagsHandler extends AbstractActionHandler
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

        if (! isset($payload['robots']) || ! is_array($payload['robots'])) {
            throw new Exception('No robots directives provided.');
        }

        $url = $this->resolveUrl($action);
        if ($postId === 0 && $url !== '') {
            $store = $this->getUrlMetaStore();
            $beforeRobots = $store->getMeta($url, 'robots');
            if (! is_array($beforeRobots)) {
                $beforeRobots = [];
            }

            $store->setMeta($url, 'robots', $payload['robots']);

            return [
                'status' => 'applied',
                'metadata' => [
                    'handler' => 'technical_seo_flags',
                    'adapter' => 'url_meta_store',
                ],
                'before' => ['robots' => $beforeRobots],
                'after' => [
                    'robots' => $payload['robots'],
                    'adapter' => 'url_meta_store',
                ],
            ];
        }

        $before = [
            'robots' => $this->adapter->getRobots($postId),
        ];

        if (! $this->adapter->setRobots($postId, $payload['robots'])) {
            throw new Exception('Failed to set robots directives.');
        }

        $after = [
            'robots' => $this->adapter->getRobots($postId),
            'adapter' => $this->adapter->getName(),
        ];

        return [
            'status' => 'applied',
            'metadata' => [
                'handler' => 'technical_seo_flags',
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

        $previous = isset($before['robots']) && is_array($before['robots']) ? $before['robots'] : [];

        $url = $this->resolveUrl($action);
        if ($postId === 0 && $url !== '') {
            $store = $this->getUrlMetaStore();
            if ($previous !== []) {
                $store->setMeta($url, 'robots', $previous);
            } else {
                $store->deleteMeta($url, 'robots');
            }

            return ['status' => 'rolled_back'];
        }

        if ($previous !== []) {
            $this->adapter->setRobots($postId, $previous);
        } else {
            $this->adapter->setRobots($postId, []);
            delete_post_meta($postId, '_seoworkerai_robots');
            delete_post_meta($postId, '_yoast_wpseo_meta-robots-noindex');
            delete_post_meta($postId, '_yoast_wpseo_meta-robots-nofollow');
            delete_post_meta($postId, 'rank_math_robots');
        }

        return ['status' => 'rolled_back'];
    }
}
