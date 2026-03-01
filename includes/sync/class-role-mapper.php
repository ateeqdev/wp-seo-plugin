<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\Sync;

final class RoleMapper
{
    public const OWNER = 'owner';
    public const ADMIN = 'admin';
    public const EDITOR = 'editor';
    public const VIEWER = 'viewer';

    public static function mapWordPressRole(?string $wpRole): string
    {
        $role = strtolower(trim((string) $wpRole));

        if ($role === 'administrator') {
            return self::ADMIN;
        }

        if (in_array($role, ['editor', 'shop_manager', 'author', 'contributor'], true)) {
            return self::EDITOR;
        }

        if (in_array($role, ['subscriber', 'customer'], true)) {
            return self::VIEWER;
        }

        return self::VIEWER;
    }
}
