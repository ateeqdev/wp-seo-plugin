<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\Actions\Handlers;

use Exception;
use SEOWorkerAI\Connector\SEO\InterfaceSeoAdapter;
use SEOWorkerAI\Connector\Utils\Logger;

final class SchemaHandler extends AbstractActionHandler
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

        $schema = null;

        if (isset($payload['schema_data']) && is_array($payload['schema_data'])) {
            $schema = $payload['schema_data'];
        } elseif (!empty($payload['json_ld']) && is_string($payload['json_ld'])) {
            $decoded = json_decode($payload['json_ld'], true);
            if (is_array($decoded)) {
                $schema = $decoded;
            }
        }

        if (!is_array($schema)) {
            throw new Exception('No schema data provided.');
        }

        if (!isset($schema['@context'])) {
            $schema['@context'] = 'https://schema.org';
        }

        if (!isset($schema['@type']) && isset($payload['schema_type'])) {
            $schema['@type'] = (string) $payload['schema_type'];
        }

        if (!isset($schema['@type'])) {
            throw new Exception('Schema missing @type.');
        }

        $json = wp_json_encode($schema);
        if (!is_string($json) || strlen($json) > 256 * 1024) {
            throw new Exception('Schema exceeds 256KB limit.');
        }

        $before = [
            'schema' => $this->adapter->getSchema($postId),
            'plugin_schema' => json_decode((string) get_post_meta($postId, '_seoworkerai_schema_json_ld', true), true),
        ];

        $adapterSuccess = $this->adapter->setSchema($postId, $schema);
        update_post_meta($postId, '_seoworkerai_schema_json_ld', $json);

        $after = [
            'schema' => $this->adapter->getSchema($postId),
            'plugin_schema' => json_decode((string) get_post_meta($postId, '_seoworkerai_schema_json_ld', true), true),
            'adapter' => $this->adapter->getName(),
            'adapter_success' => $adapterSuccess,
        ];

        return [
            'status' => 'applied',
            'metadata' => [
                'handler' => 'schema',
                'schema_type' => (string) $schema['@type'],
                'adapter' => $this->adapter->getName(),
                'adapter_success' => $adapterSuccess,
            ],
            'before' => $before,
            'after' => $after,
        ];
    }

    /**
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    public function rollback(array $action): array
    {
        $postId = $this->resolvePostId($action);
        $rawBefore = isset($action['before_snapshot']) ? (string) $action['before_snapshot'] : '';
        $before = json_decode($rawBefore, true);

        if (!is_array($before)) {
            return ['status' => 'failed', 'error' => 'Missing before snapshot'];
        }

        $schema = isset($before['schema']) && is_array($before['schema']) ? $before['schema'] : null;
        $pluginSchema = isset($before['plugin_schema']) && is_array($before['plugin_schema']) ? $before['plugin_schema'] : null;

        if (is_array($schema) && $schema !== []) {
            $this->adapter->setSchema($postId, $schema);
        } else {
            delete_post_meta($postId, '_yoast_wpseo_schema');
            delete_post_meta($postId, '_aioseo_schema');
            delete_post_meta($postId, '_seoworkerai_schema_json_ld');
        }

        if (is_array($pluginSchema) && $pluginSchema !== []) {
            update_post_meta($postId, '_seoworkerai_schema_json_ld', wp_json_encode($pluginSchema));
        } else {
            delete_post_meta($postId, '_seoworkerai_schema_json_ld');
        }

        return ['status' => 'rolled_back'];
    }
}
