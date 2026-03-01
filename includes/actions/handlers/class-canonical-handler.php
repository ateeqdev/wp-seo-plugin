<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\Actions\Handlers;

use Exception;
use SEOAutomation\Connector\SEO\InterfaceSeoAdapter;
use SEOAutomation\Connector\Utils\Logger;

final class CanonicalHandler extends AbstractActionHandler
{
    private InterfaceSeoAdapter $adapter;

    public function __construct(InterfaceSeoAdapter $adapter, Logger $logger)
    {
        parent::__construct($logger);
        $this->adapter = $adapter;
    }

    /**
     * @param array<string, mixed> $action
     * @return bool|\WP_Error
     */
    public function validate(array $action)
    {
        $postId = $this->resolvePostId($action);
        $post = get_post($postId);

        if (!$post || $post->post_status === 'trash') {
            return new \WP_Error('missing_post', 'Target post not found.');
        }

        return true;
    }

    /**
     * @param array<string, mixed> $action
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

        if ((bool) get_option('seoauto_canonical_same_host', true)) {
            $siteHost = (string) wp_parse_url(home_url(), PHP_URL_HOST);
            $canonicalHost = (string) wp_parse_url($canonical, PHP_URL_HOST);

            if ($siteHost !== '' && $canonicalHost !== '' && strtolower($siteHost) !== strtolower($canonicalHost)) {
                throw new Exception('Canonical URL must use the same host.');
            }
        }

        $before = [
            'canonical' => (string) ($this->adapter->getCanonical($postId) ?? ''),
        ];

        if (!$this->adapter->setCanonical($postId, $canonical)) {
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
}
