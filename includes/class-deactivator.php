<?php

declare(strict_types=1);

namespace SEOAutomation\Connector;

use SEOAutomation\Connector\Queue\QueueManager;

final class Deactivator
{
    public static function deactivate(): void
    {
        QueueManager::unscheduleRecurringJobs();
        flush_rewrite_rules();
    }
}
