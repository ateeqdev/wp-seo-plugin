<?php

declare(strict_types=1);

namespace SEOAutomation\Connector;

use SEOAutomation\Connector\Queue\QueueManager;

final class Deactivator
{
    public static function deactivate(): void
    {
        QueueManager::unscheduleRecurringJobs();
        $timestamp = wp_next_scheduled('seoauto_auto_register_site');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'seoauto_auto_register_site');
        }
        flush_rewrite_rules();
    }
}
