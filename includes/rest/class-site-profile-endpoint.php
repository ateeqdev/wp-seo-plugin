<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\REST;

use SEOWorkerAI\Connector\Auth\SiteTokenManager;

final class SiteProfileEndpoint
{
    private SiteTokenManager $tokenManager;

    public function __construct(SiteTokenManager $tokenManager)
    {
        $this->tokenManager = $tokenManager;
    }

    public function registerRoutes(): void
    {
        $namespace = 'seoworkerai/v1';
        register_rest_route($namespace, '/site-profile', [
            'methods' => 'PUT',
            'callback' => [$this, 'updateSiteProfile'],
            'permission_callback' => [$this, 'authorize'],
        ]);
    }

    public function authorize(\WP_REST_Request $request)
    {
        $token = (string) $request->get_header('X-Site-Token');
        if (! $this->tokenManager->verifyInboundToken($token)) {
            return new \WP_Error('seoworkerai_unauthorized', 'Invalid site token.', ['status' => 401]);
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Route handlers
    // -------------------------------------------------------------------------

    /** @return \WP_REST_Response|\WP_Error */
    public function updateSiteProfile(\WP_REST_Request $request)
    {
        $body = $request->get_json_params();

        $description = isset($body['description']) ? trim((string) $body['description']) : '';
        $taste = isset($body['taste']) ? trim((string) $body['taste']) : '';

        if ($description === '' || $taste === '') {
            return new \WP_Error(
                'seoworkerai_invalid_payload',
                'Both description and taste are required.',
                ['status' => 422]
            );
        }

        update_option('seoworkerai_site_profile_description', $description, false);
        update_option('seoworkerai_site_profile_taste', $taste, false);

        return new \WP_REST_Response([
            'status' => 'ok',
            'message' => 'Site profile updated.',
            'data' => [
                'description' => $description,
                'taste' => $taste,
            ],
        ]);
    }
}
