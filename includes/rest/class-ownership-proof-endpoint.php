<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\REST;

use SEOWorkerAI\Connector\Auth\OwnershipProofStore;

final class OwnershipProofEndpoint
{
    public function registerRoutes(): void
    {
        $namespace = 'seoworkerai/v1';
        register_rest_route($namespace, '/ownership-proof', [
            'methods' => 'GET',
            'callback' => [$this, 'showProof'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function showProof(\WP_REST_Request $request)
    {
        OwnershipProofStore::cleanup();

        $challengeId = sanitize_text_field((string) $request->get_param('challenge_id'));
        if ($challengeId === '') {
            return new \WP_Error('seoworkerai_missing_challenge', 'Missing challenge_id.', ['status' => 422]);
        }

        $token = OwnershipProofStore::getToken($challengeId);
        if ($token === null || $token === '') {
            return new \WP_Error('seoworkerai_challenge_not_found', 'Challenge not found.', ['status' => 404]);
        }

        $response = new \WP_REST_Response($token, 200);
        $response->header('Content-Type', 'text/plain; charset=utf-8');

        return $response;
    }
}
