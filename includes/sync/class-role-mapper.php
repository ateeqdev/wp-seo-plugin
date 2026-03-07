<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\Sync;

final class RoleMapper
{
    public const OWNER = 'owner';
    public const EDITOR = 'editor';
    public const VIEWER = 'viewer';

    public static function mapWordPressRole(?string $wpRole): string
    {
        $role = strtolower(trim((string) $wpRole));

        if ($role === 'administrator') {
            return self::OWNER;
        }

        if (in_array($role, ['editor', 'shop_manager', 'author', 'contributor'], true)) {
            return self::EDITOR;
        }

        if (in_array($role, ['subscriber', 'customer'], true)) {
            return self::VIEWER;
        }

        return self::VIEWER;
    }

    /**
     * @param array<int, string> $wpRoles
     */
    public static function mapWordPressRoles(array $wpRoles): string
    {
        $normalizedRoles = array_values(array_filter(array_map(
            static fn ($role): string => strtolower(trim((string) $role)),
            $wpRoles
        )));

        if (in_array('administrator', $normalizedRoles, true)) {
            return self::OWNER;
        }

        foreach ($normalizedRoles as $role) {
            if (self::mapWordPressRole($role) === self::EDITOR) {
                return self::EDITOR;
            }
        }

        return self::VIEWER;
    }
}
