<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\SEO;

abstract class AbstractMetaBackedAdapter implements InterfaceSeoAdapter
{
    /**
     * @param array<int, string> $keys
     */
    protected function readFirstMeta(int $postId, array $keys): string
    {
        foreach ($keys as $key) {
            $value = (string) get_post_meta($postId, $key, true);

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<int, string> $keys
     */
    protected function readOptionalMeta(int $postId, array $keys): ?string
    {
        $value = $this->readFirstMeta($postId, $keys);

        return $value !== '' ? $value : null;
    }

    /**
     * @param array<int, string> $keys
     */
    protected function writeMeta(int $postId, array $keys, string $value): bool
    {
        $updated = false;

        foreach ($keys as $key) {
            $updated = (bool) update_post_meta($postId, $key, $value) || $updated;
        }

        return $updated;
    }

    /**
     * @param array<string, array<string, array<int, string>>> $metaMap
     * @return array<string, array<string, string>>
     */
    protected function buildSocialTags(int $postId, array $metaMap): array
    {
        $social_tags = [];

        foreach ($metaMap as $namespace => $fields) {
            $social_tags[$namespace] = [];

            foreach ($fields as $field => $keys) {
                $social_tags[$namespace][$field] = $this->readFirstMeta($postId, $keys);
            }
        }

        return $social_tags;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function readJsonMeta(int $postId, string $key): ?array
    {
        $json = get_post_meta($postId, $key, true);

        if (! is_string($json) || $json === '') {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $schema
     */
    protected function writeJsonMeta(int $postId, string $key, array $schema): bool
    {
        return (bool) update_post_meta($postId, $key, wp_json_encode($schema));
    }
}
