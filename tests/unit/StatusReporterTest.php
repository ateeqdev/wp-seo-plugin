<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SEOAutomation\Connector\Actions\StatusReporter;

final class StatusReporterTest extends TestCase
{
    public function testMapProviderStatusSupportsRolledBack(): void
    {
        $ref = new ReflectionClass(StatusReporter::class);
        /** @var StatusReporter $reporter */
        $reporter = $ref->newInstanceWithoutConstructor();

        $mapper = new ReflectionMethod($reporter, 'mapProviderStatus');
        $mapper->setAccessible(true);

        self::assertSame('provider-applied', $mapper->invoke($reporter, 'applied'));
        self::assertSame('provider-error', $mapper->invoke($reporter, 'failed'));
        self::assertSame('provider-rolled-back', $mapper->invoke($reporter, 'rolled_back'));
    }
}

