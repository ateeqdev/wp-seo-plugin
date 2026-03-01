<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\Actions\Handlers;

use Exception;
use SEOAutomation\Connector\SEO\InterfaceSeoAdapter;
use SEOAutomation\Connector\Utils\Logger;

final class MetaDescriptionHandler extends AbstractActionHandler
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

        $description = (string) (
            $payload['meta_description']
            ?? $payload['recommended_meta_description']
            ?? ($payload['meta_description_variants'][0] ?? '')
        );

        $description = trim($description);

        if ($description === '') {
            throw new Exception('No meta description provided.');
        }

        $length = strlen($description);
        if ($length < 70 || $length > 320) {
            throw new Exception('Meta description must be between 70 and 320 characters.');
        }

        $before = [
            'meta_description' => (string) ($this->adapter->getDescription($postId) ?? ''),
        ];

        if (trim($before['meta_description']) === trim($description)) {
            return [
                'status' => 'applied',
                'metadata' => ['noop' => true],
                'before' => $before,
                'after' => $before,
            ];
        }

        if (!$this->adapter->setDescription($postId, $description)) {
            throw new Exception('Adapter failed to set description.');
        }

        $after = [
            'meta_description' => (string) ($this->adapter->getDescription($postId) ?? ''),
            'adapter' => $this->adapter->getName(),
        ];

        return [
            'status' => 'applied',
            'metadata' => [
                'handler' => 'meta_description',
                'adapter' => $this->adapter->getName(),
            ],
            'before' => $before,
            'after' => $after,
        ];
    }
}
