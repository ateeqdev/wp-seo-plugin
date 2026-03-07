<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SEOAutomation\Connector\Sync\RoleMapper;

final class RoleMapperTest extends TestCase
{
    public function testAdministratorMapsToOwnerContractRole(): void
    {
        self::assertSame(RoleMapper::OWNER, RoleMapper::mapWordPressRole('administrator'));
    }

    public function testEditorialRolesMapToEditorContractRole(): void
    {
        self::assertSame(RoleMapper::EDITOR, RoleMapper::mapWordPressRole('editor'));
        self::assertSame(RoleMapper::EDITOR, RoleMapper::mapWordPressRole('shop_manager'));
        self::assertSame(RoleMapper::EDITOR, RoleMapper::mapWordPressRole('author'));
        self::assertSame(RoleMapper::EDITOR, RoleMapper::mapWordPressRole('contributor'));
    }

    public function testReadOnlyRolesMapToViewerContractRole(): void
    {
        self::assertSame(RoleMapper::VIEWER, RoleMapper::mapWordPressRole('subscriber'));
        self::assertSame(RoleMapper::VIEWER, RoleMapper::mapWordPressRole('customer'));
    }

    public function testUnknownRoleFallsBackToViewer(): void
    {
        self::assertSame(RoleMapper::VIEWER, RoleMapper::mapWordPressRole('custom_role'));
        self::assertSame(RoleMapper::VIEWER, RoleMapper::mapWordPressRole(null));
    }

    public function testRoleArrayUsesHighestPriorityRole(): void
    {
        self::assertSame(RoleMapper::OWNER, RoleMapper::mapWordPressRoles(['subscriber', 'administrator']));
        self::assertSame(RoleMapper::EDITOR, RoleMapper::mapWordPressRoles(['subscriber', 'editor']));
        self::assertSame(RoleMapper::VIEWER, RoleMapper::mapWordPressRoles(['subscriber', 'customer']));
    }
}
