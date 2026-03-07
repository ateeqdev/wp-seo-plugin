<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SEOWorkerAI\Connector\Admin\MenuRegistrar;

final class MenuRegistrarHelpersTest extends TestCase
{
    public function testShouldRenderTimelineNoteSuppressesSystemNoise(): void
    {
        $registrar = $this->newRegistrarWithoutConstructor();
        $method = new ReflectionMethod($registrar, 'shouldRenderTimelineNote');
        $method->setAccessible(true);

        self::assertFalse($method->invoke($registrar, 'queued', 'Action queued for execution.'));
        self::assertFalse($method->invoke($registrar, 'running', ''));
        self::assertTrue($method->invoke($registrar, 'failed', 'Rollback failed: missing snapshot'));
    }

    public function testResolveTimelineEventLabelNormalizesQueuedRunning(): void
    {
        $registrar = $this->newRegistrarWithoutConstructor();
        $method = new ReflectionMethod($registrar, 'resolveTimelineEventLabel');
        $method->setAccessible(true);

        self::assertSame('running', $method->invoke($registrar, 'queued', 'running'));
        self::assertSame('edited', $method->invoke($registrar, 'edited', 'received'));
        self::assertSame('failed', $method->invoke($registrar, '', 'failed'));
    }

    public function testBuildReadOnlyFieldsCoversCoreActionShapes(): void
    {
        $registrar = $this->newRegistrarWithoutConstructor();
        $method = new ReflectionMethod($registrar, 'buildReadOnlyFields');
        $method->setAccessible(true);

        $metaFields = $method->invoke($registrar, 'add-meta-description', [
            'meta_description' => 'Meta text',
        ], [], []);
        self::assertSame('Meta Description', $metaFields[0]['label']);
        self::assertSame('Meta text', $metaFields[0]['value']);

        $socialFields = $method->invoke($registrar, 'set-social-tags', [
            'social_tags' => [
                'og' => ['title' => 'OG headline'],
                'twitter' => ['site' => '@hardtoskip'],
            ],
        ], [], []);
        self::assertCount(2, $socialFields);
        self::assertSame('OG Title', $socialFields[0]['label']);
        self::assertSame('@hardtoskip', $socialFields[1]['value']);

        $fallbackFields = $method->invoke($registrar, 'custom-action', [], [], [
            'changed_key' => 'Updated value',
        ]);
        self::assertSame('Changed Key', $fallbackFields[0]['label']);
        self::assertSame('Updated value', $fallbackFields[0]['value']);
    }

    private function newRegistrarWithoutConstructor(): MenuRegistrar
    {
        $ref = new ReflectionClass(MenuRegistrar::class);

        /** @var MenuRegistrar $instance */
        $instance = $ref->newInstanceWithoutConstructor();

        return $instance;
    }
}

