<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\REST;

use SEOAutomation\Connector\Auth\SiteTokenManager;

final class MediaEndpoint
{
    private SiteTokenManager $tokenManager;

    public function __construct(SiteTokenManager $tokenManager)
    {
        $this->tokenManager = $tokenManager;
    }

    public function registerRoutes(): void
    {
        register_rest_route('seoauto/v1', '/media', [
            'methods' => 'GET',
            'callback' => [$this, 'listMedia'],
            'permission_callback' => [$this, 'authorize'],
        ]);
    }

    public function authorize(\WP_REST_Request $request)
    {
        $token = (string) $request->get_header('X-Site-Token');

        if (!$this->tokenManager->verifyInboundToken($token)) {
            return new \WP_Error('seoauto_unauthorized', 'Invalid site token.', ['status' => 401]);
        }

        return true;
    }

    /**
     * @return \WP_REST_Response
     */
    public function listMedia(\WP_REST_Request $request)
    {
        $page = max(1, (int) $request->get_param('page'));
        $perPage = min(200, max(1, (int) $request->get_param('per_page')));
        $modifiedAfter = (string) ($request->get_param('modified_after') ?? '');

        $args = [
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => $perPage,
            'paged' => $page,
            'orderby' => 'modified',
            'order' => 'DESC',
        ];

        if ($modifiedAfter !== '') {
            $args['date_query'] = [
                [
                    'column' => 'post_modified_gmt',
                    'after' => $modifiedAfter,
                ],
            ];
        }

        $query = new \WP_Query($args);
        $items = [];

        foreach ($query->posts as $post) {
            if (!$post instanceof \WP_Post) {
                continue;
            }

            $items[] = [
                'id' => $post->ID,
                'url' => wp_get_attachment_url($post->ID),
                'mime_type' => $post->post_mime_type,
                'title' => get_the_title($post->ID),
                'alt_text' => (string) get_post_meta($post->ID, '_wp_attachment_image_alt', true),
                'caption' => (string) wp_get_attachment_caption($post->ID),
                'modified_gmt' => gmdate('c', strtotime((string) $post->post_modified_gmt)),
            ];
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
}
