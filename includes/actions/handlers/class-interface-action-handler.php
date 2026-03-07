<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\Actions\Handlers;

interface InterfaceActionHandler
{
    /**
     * @param array<string, mixed> $action
     * @return bool|\WP_Error
     */
    public function validate(array $action);

    /**
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    public function execute(array $action): array;

    /**
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    public function rollback(array $action): array;
}
