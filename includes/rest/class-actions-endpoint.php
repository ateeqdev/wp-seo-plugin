<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\REST;

use SEOWorkerAI\Connector\Actions\ActionReceiver;
use SEOWorkerAI\Connector\Auth\SiteTokenManager;

final class ActionsEndpoint
{
    private SiteTokenManager $tokenManager;

    private ActionReceiver $receiver;

    public function __construct(SiteTokenManager $tokenManager, ActionReceiver $receiver)
    {
        $this->tokenManager = $tokenManager;
        $this->receiver = $receiver;
    }

    public function registerRoutes(): void
    {
        $namespace = 'seoworkerai/v1';
        register_rest_route($namespace, '/actions/execute', [
            'methods' => 'POST',
            'callback' => [$this, 'executeAction'],
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

    /**
     * @return \WP_REST_Response
     */
    public function executeAction(\WP_REST_Request $request)
    {
        $payload = $request->get_json_params();
        if (! is_array($payload)) {
            $payload = [];
        }

        $result = $this->receiver->receivePushAction($payload);
        $status = (string) ($result['status'] ?? 'error');

        if ($status === 'accepted') {
            return new \WP_REST_Response($result, 202);
        }

        if ($status === 'already_received') {
            return new \WP_REST_Response($result, 200);
        }

        return new \WP_REST_Response($result, 422);
    }
}
