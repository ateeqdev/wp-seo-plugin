<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\Content;

final class RollbackManager
{
    /**
     * @return array<string, mixed>
     */
    public function capturePostSnapshot(int $postId): array
    {
        $post = get_post($postId);

        if (!$post instanceof \WP_Post) {
            return [];
        }

        $metaKeys = [
            '_yoast_wpseo_title',
            '_yoast_wpseo_metadesc',
            '_yoast_wpseo_canonical',
            '_rank_math_title',
            '_rank_math_description',
            '_rank_math_canonical_url',
            '_aioseo_title',
            '_aioseo_description',
            '_aioseo_canonical_url',
            '_seoworkerai_title',
            '_seoworkerai_meta_description',
            '_seoworkerai_canonical',
            '_seoworkerai_schema_json_ld',
        ];

        $meta = [];
        foreach ($metaKeys as $key) {
            $exists = metadata_exists('post', $postId, $key);
            $meta[$key] = [
                'exists' => $exists,
                'value' => $exists ? get_post_meta($postId, $key, true) : null,
            ];
        }

        return [
            'snapshot_version' => 1,
            'post_id' => $postId,
            'post_content' => (string) $post->post_content,
            'post_title' => (string) $post->post_title,
            'post_excerpt' => (string) $post->post_excerpt,
            'post_status' => (string) $post->post_status,
            'content_checksum' => hash('sha256', (string) $post->post_content),
            'meta' => $meta,
            'captured_at' => gmdate('c'),
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public function restorePostSnapshot(int $postId, array $snapshot): bool
    {
        if (empty($snapshot)) {
            return false;
        }

        $update = [
            'ID' => $postId,
            'post_content' => (string) ($snapshot['post_content'] ?? ''),
            'post_title' => (string) ($snapshot['post_title'] ?? ''),
            'post_excerpt' => (string) ($snapshot['post_excerpt'] ?? ''),
            'post_status' => (string) ($snapshot['post_status'] ?? 'draft'),
        ];

        $result = wp_update_post($update, true);

        if (is_wp_error($result)) {
            return false;
        }

        $meta = isset($snapshot['meta']) && is_array($snapshot['meta']) ? $snapshot['meta'] : [];
        foreach ($meta as $key => $entry) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            if (is_array($entry) && array_key_exists('exists', $entry)) {
                $exists = (bool) $entry['exists'];
                $value = $entry['value'] ?? null;

                if (!$exists) {
                    delete_post_meta($postId, $key);
                    continue;
                }

                if ($value === null) {
                    delete_post_meta($postId, $key);
                    continue;
                }

                update_post_meta($postId, $key, $value);
                continue;
            }

            // Backward-compatibility for pre-v2 snapshots that only stored value.
            if ($entry === '') {
                delete_post_meta($postId, $key);
                continue;
            }

            update_post_meta($postId, $key, $entry);
        }

        return true;
    }
}
