<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\Actions\Handlers;

use SEOWorkerAI\Connector\Actions\ActionRepository;
use SEOWorkerAI\Connector\Utils\Logger;

final class HumanActionRequiredHandler extends AbstractActionHandler
{
    private ActionRepository $repository;

    public function __construct(ActionRepository $repository, Logger $logger)
    {
        parent::__construct($logger);
        $this->repository = $repository;
    }

    /**
     * @param array<string, mixed> $action
     * @return bool|\WP_Error
     */
    public function validate(array $action)
    {
        return true;
    }

    /**
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    public function execute(array $action): array
    {
        $laravelActionId = (int) ($action['laravel_action_id'] ?? 0);
        $payload = $this->payload($action);

        if ($laravelActionId > 0) {
            $this->repository->addAdminActionItem($laravelActionId, $payload);
        }

        return [
            'status' => 'applied',
            'metadata' => [
                'handler' => 'human_action_required',
                'created_admin_action_item' => $laravelActionId > 0,
            ],
            'before' => [],
            'after' => $payload,
        ];
    }
}
