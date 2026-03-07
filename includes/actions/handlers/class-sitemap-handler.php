<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\Actions\Handlers;

use SEOWorkerAI\Connector\Utils\Logger;

final class SitemapHandler extends AbstractActionHandler
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
        $urls = [home_url('/wp-sitemap.xml')];

        if (defined('WPSEO_VERSION') || defined('RANK_MATH_VERSION')) {
            $urls[] = home_url('/sitemap_index.xml');
        }

        $warmed = [];

        foreach ($urls as $url) {
            $response = wp_remote_get($url, ['timeout' => 15]);
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) < 400) {
                $warmed[] = $url;
            }
        }

        delete_transient('wp_sitemap_posts_1');
        delete_transient('wp_sitemap_pages_1');

        return [
            'status' => 'applied',
            'metadata' => [
                'handler' => 'sitemap_update',
                'warmed_urls' => $warmed,
            ],
            'before' => [],
            'after' => ['warmed_urls' => $warmed],
        ];
    }
}
