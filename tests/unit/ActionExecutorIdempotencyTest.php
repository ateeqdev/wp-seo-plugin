<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SEOWorkerAI\Connector\Actions\ActionExecutor;

final class ActionExecutorIdempotencyTest extends TestCase
{
    public function testHasAlreadyAppliedPayloadReturnsTrueWhenChecksumMatches(): void
    {
        $executor = (new ReflectionClass(ActionExecutor::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ActionExecutor::class, 'hasAlreadyAppliedPayload');
        $method->setAccessible(true);

        $result = $method->invoke($executor, [
            'status' => 'queued',
            'payload_checksum' => 'abc123',
            'last_applied_checksum' => 'abc123',
        ]);

        self::assertTrue($result);
    }

    public function testHasAlreadyAppliedPayloadReturnsFalseAfterRollback(): void
    {
        $executor = (new ReflectionClass(ActionExecutor::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ActionExecutor::class, 'hasAlreadyAppliedPayload');
        $method->setAccessible(true);

        $result = $method->invoke($executor, [
            'status' => 'rolled_back',
            'payload_checksum' => 'abc123',
            'last_applied_checksum' => 'abc123',
        ]);

        self::assertFalse($result);
    }
}
