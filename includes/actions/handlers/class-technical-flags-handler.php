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

        if (!isset($payload['robots']) || !is_array($payload['robots'])) {
            throw new Exception('No robots directives provided.');
        }

        $before = [
            'robots' => $this->adapter->getRobots($postId),
        ];

        if (!$this->adapter->setRobots($postId, $payload['robots'])) {
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
}
