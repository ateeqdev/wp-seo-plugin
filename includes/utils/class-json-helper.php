<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\Utils;

final class JsonHelper
{
    /**
     * @param mixed $data
     */
    public static function encode($data): string
    {
        $json = wp_json_encode($data);

        return $json !== false ? $json : '{}';
    }

    /**
     * @return array<string, mixed>
     */
    public static function decodeArray(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
