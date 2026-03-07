<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\REST;

use SEOWorkerAI\Connector\Auth\SiteTokenManager;
use SEOWorkerAI\Connector\SEO\SeoDetector;

final class PagesEndpoint
{
    private SiteTokenManager $tokenManager;

    private SeoDetector $seoDetector;

    public function __construct(SiteTokenManager $tokenManager, SeoDetector $seoDetector)
    {
        $this->tokenManager = $tokenManager;
        $this->seoDetector = $seoDetector;
    }

    public function registerRoutes(): void
    {
        foreach (['seoworkerai/v1', 'seo-platform/v1'] as $namespace) {
            register_rest_route($namespace, '/pages', [
                'methods' => 'GET',
                'callback' => [$this, 'listPages'],
                'permission_callback' => [$this, 'authorize'],
            ]);

            register_rest_route($namespace, '/pages/(?P<id>\d+)', [
                'methods' => 'GET',
                'callback' => [$this, 'getPage'],
                'permission_callback' => [$this, 'authorize'],
            ]);
        }
    }

    public function authorize(\WP_REST_Request $request)
    {
        $token = (string) $request->get_header('X-Site-Token');

        if (!$this->tokenManager->verifyInboundToken($token)) {
            return new \WP_Error('seoworkerai_unauthorized', 'Invalid site token.', ['status' => 401]);
        }

        return true;
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function listPages(\WP_REST_Request $request)
    {
        $page = max(1, (int) $request->get_param('page'));
        $perPage = min(200, max(1, (int) $request->get_param('per_page')));
        $postType = (string) ($request->get_param('post_type') ?? 'any');
        $modifiedAfter = (string) ($request->get_param('modified_after') ?? '');

        $allowedTypes = ['any', 'post', 'page', 'product'];
        if (!in_array($postType, $allowedTypes, true)) {
            $postType = 'any';
        }

        $queryArgs = [
            'post_type' => $postType,
            'post_status' => 'publish',
            'posts_per_page' => $perPage,
            'paged' => $page,
            'orderby' => 'modified',
            'order' => 'DESC',
        ];

        if ($modifiedAfter !== '') {
            $queryArgs['date_query'] = [
                [
                    'column' => 'post_modified_gmt',
                    'after' => $modifiedAfter,
                ],
            ];
        }

        $query = new \WP_Query($queryArgs);
        $items = [];

        foreach ($query->posts as $post) {
            if ($post instanceof \WP_Post) {
                $items[] = $this->formatPost($post);
            }
        }

        return new \WP_REST_Response([
            'status' => 'ok',
            'data' => [
                'items' => $items,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'has_more' => $query->max_num_pages > $page,
                ],
            ],
        ]);
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function getPage(\WP_REST_Request $request)
    {
        $postId = (int) $request->get_param('id');
        $post = get_post($postId);

        if (!$post instanceof \WP_Post || $post->post_status !== 'publish') {
            return new \WP_Error('seoworkerai_not_found', 'Post not found.', ['status' => 404]);
        }

        return new \WP_REST_Response([
            'status' => 'ok',
            'data' => $this->formatPost($post),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatPost(\WP_Post $post): array
    {
        $adapter = $this->seoDetector->getAdapter();

        return [
            'id' => $post->ID,
            'post_type' => $post->post_type,
            'status' => $post->post_status,
            'url' => get_permalink($post->ID),
            'slug' => $post->post_name,
            'title' => get_the_title($post->ID),
            'excerpt' => get_the_excerpt($post->ID),
            'content_html' => apply_filters('the_content', $post->post_content),
            'content_text' => wp_strip_all_tags($post->post_content),
            'seo' => [
                'title' => $adapter->getTitle($post->ID),
                'meta_description' => $adapter->getDescription($post->ID),
                'canonical' => $adapter->getCanonical($post->ID),
                'robots' => $adapter->getRobots($post->ID),
                'schema_markup' => $adapter->getSchema($post->ID),
                'social_tags' => $adapter->getSocialTags($post->ID),
            ],
            'published_at' => gmdate('c', strtotime((string) $post->post_date_gmt)),
            'modified_gmt' => gmdate('c', strtotime((string) $post->post_modified_gmt)),
        ];
    }
}
