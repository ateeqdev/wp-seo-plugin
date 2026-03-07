<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\Actions\Handlers;

use Exception;
use SEOWorkerAI\Connector\Utils\Logger;

final class PostDatesHandler extends AbstractActionHandler
{
    public function __construct(Logger $logger)
    {
        parent::__construct($logger);
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
        $post = get_post($postId);
        $payload = $this->payload($action);

        if (!$post instanceof \WP_Post) {
            throw new Exception('Post not found.');
        }

        $onlyIfMissing = !empty($payload['only_if_missing']);
        $publishedAt = (string) ($payload['published_at'] ?? '');
        $modifiedAt = (string) ($payload['modified_at'] ?? '');

        if ($publishedAt === '' && $modifiedAt === '') {
            throw new Exception('No date values provided.');
        }

        $before = [
            'post_date_gmt' => (string) $post->post_date_gmt,
            'post_modified_gmt' => (string) $post->post_modified_gmt,
        ];

        $updates = ['ID' => $postId];

        if (!$onlyIfMissing || $post->post_date_gmt === '0000-00-00 00:00:00' || $post->post_date_gmt === '') {
            if ($publishedAt !== '') {
                $publishedTs = strtotime($publishedAt);
                if ($publishedTs !== false) {
                    $updates['post_date'] = gmdate('Y-m-d H:i:s', $publishedTs);
                    $updates['post_date_gmt'] = gmdate('Y-m-d H:i:s', $publishedTs);
                }
            }
        }

        if (!$onlyIfMissing || $post->post_modified_gmt === '0000-00-00 00:00:00' || $post->post_modified_gmt === '') {
            if ($modifiedAt !== '') {
                $modifiedTs = strtotime($modifiedAt);
                if ($modifiedTs !== false) {
                    $updates['post_modified'] = gmdate('Y-m-d H:i:s', $modifiedTs);
                    $updates['post_modified_gmt'] = gmdate('Y-m-d H:i:s', $modifiedTs);
                }
            }
        }

        if (count($updates) > 1) {
            $result = wp_update_post($updates, true);
            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
            }
        }

        $afterPost = get_post($postId);
        $after = [
            'post_date_gmt' => $afterPost instanceof \WP_Post ? (string) $afterPost->post_date_gmt : '',
            'post_modified_gmt' => $afterPost instanceof \WP_Post ? (string) $afterPost->post_modified_gmt : '',
        ];

        return [
            'status' => 'applied',
            'metadata' => ['handler' => 'set_post_dates'],
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

        $update = [
            'ID' => $postId,
            'post_date_gmt' => (string) ($before['post_date_gmt'] ?? ''),
            'post_modified_gmt' => (string) ($before['post_modified_gmt'] ?? ''),
            'post_date' => (string) ($before['post_date_gmt'] ?? ''),
            'post_modified' => (string) ($before['post_modified_gmt'] ?? ''),
        ];

        $result = wp_update_post($update, true);

        if (is_wp_error($result)) {
            return ['status' => 'failed', 'error' => $result->get_error_message()];
        }

        return ['status' => 'rolled_back'];
    }
}
