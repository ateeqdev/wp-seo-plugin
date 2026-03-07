<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\Queue;

use SEOAutomation\Connector\Actions\ActionExecutor;
use SEOAutomation\Connector\Actions\ActionPoller;
use SEOAutomation\Connector\Actions\StatusReporter;
use SEOAutomation\Connector\Events\EventDispatcher;
use SEOAutomation\Connector\Sync\BriefSyncer;
use SEOAutomation\Connector\Sync\UserSyncer;
use SEOAutomation\Connector\Utils\Logger;

final class QueueManager
{
    private EventDispatcher $eventDispatcher;

    private ActionPoller $actionPoller;

    private BriefSyncer $briefSyncer;

    private UserSyncer $userSyncer;

    private ActionExecutor $actionExecutor;

    private StatusReporter $statusReporter;

    private Logger $logger;

    public function __construct(
        EventDispatcher $eventDispatcher,
        ActionPoller $actionPoller,
        BriefSyncer $briefSyncer,
        UserSyncer $userSyncer,
        ActionExecutor $actionExecutor,
        StatusReporter $statusReporter,
        Logger $logger
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->actionPoller = $actionPoller;
        $this->briefSyncer = $briefSyncer;
        $this->userSyncer = $userSyncer;
        $this->actionExecutor = $actionExecutor;
        $this->statusReporter = $statusReporter;
        $this->logger = $logger;
    }

    public function registerHooks(): void
    {
        add_action('seoauto_flush_events', function (): void {
            $this->touchHeartbeat();
            $this->eventDispatcher->flushQueuedEvents();
        });

        add_action('seoauto_poll_actions', function (): void {
            $this->touchHeartbeat();
            $this->actionPoller->poll();
        });

        add_action('seoauto_sync_briefs', function (): void {
            $this->touchHeartbeat();
            $this->briefSyncer->sync();
        });

        add_action('seoauto_sync_users', function (): void {
            $this->touchHeartbeat();
            $this->userSyncer->sync();
        });

        add_action('seoauto_cleanup', function (): void {
            $this->touchHeartbeat();
            $this->cleanup();
        });

        add_action('seoauto_execute_action', [$this, 'executeAction'], 10, 1);
        add_action('seoauto_retry_ack', [$this, 'retryAck'], 10, 1);

        add_filter('cron_schedules', [$this, 'addCronSchedules']);
    }

    /**
     * @param array<string, mixed>|int $args
     */
    public function executeAction($args): void
    {
        $this->touchHeartbeat();

        if (is_array($args)) {
            $this->actionExecutor->executeByArgs($args);
            return;
        }

        $this->actionExecutor->executeByLaravelId((int) $args);
    }

    /**
     * @param array<string, mixed>|int $args
     */
    public function retryAck($args): void
    {
        $this->touchHeartbeat();

        if (is_array($args)) {
            $this->statusReporter->retryAck($args);
            return;
        }

        $this->statusReporter->retryAck(['action_id' => (int) $args]);
    }

    public function cleanup(): void
    {
        global $wpdb;

        $actions = $wpdb->prefix . 'seoauto_actions';
        $events = $wpdb->prefix . 'seoauto_outbox';
        $logs = $wpdb->prefix . 'seoauto_logs';
        $locks = $wpdb->prefix . 'seoauto_locks';

        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->prepare("DELETE FROM {$events} WHERE created_at < DATE_SUB(%s, INTERVAL 30 DAY)", current_time('mysql'))
        );
        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->prepare("DELETE FROM {$logs} WHERE created_at < DATE_SUB(%s, INTERVAL 30 DAY)", current_time('mysql'))
        );
        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->prepare(
                "DELETE FROM {$actions} WHERE updated_at < DATE_SUB(%s, INTERVAL 30 DAY) AND status IN ('applied','failed','rejected','ack_failed')",
                current_time('mysql')
            )
        );
        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->prepare("DELETE FROM {$locks} WHERE expires_at < %s", current_time('mysql'))
        );
    }

    /**
     * @param array<string, mixed> $schedules
     * @return array<string, mixed>
     */
    public function addCronSchedules(array $schedules): array
    {
        if (!isset($schedules['seoauto_every_minute'])) {
            $schedules['seoauto_every_minute'] = [
                'interval' => MINUTE_IN_SECONDS,
                'display' => 'Every Minute (SEO Automation)',
            ];
        }

        if (!isset($schedules['seoauto_five_minutes'])) {
            $schedules['seoauto_five_minutes'] = [
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display' => 'Every Five Minutes (SEO Automation)',
            ];
        }

        if (!isset($schedules['seoauto_ten_minutes'])) {
            $schedules['seoauto_ten_minutes'] = [
                'interval' => 10 * MINUTE_IN_SECONDS,
                'display' => 'Every Ten Minutes (SEO Automation)',
            ];
        }

        return $schedules;
    }

    public static function hasActionScheduler(): bool
    {
        return function_exists('as_schedule_single_action') && function_exists('as_schedule_recurring_action');
    }

    public static function scheduleRecurringJobs(): void
    {
        if (self::hasActionScheduler()) {
            self::scheduleActionSchedulerJobs();
            return;
        }

        self::scheduleWpCronJobs();
    }

    public static function unscheduleRecurringJobs(): void
    {
        $hooks = [
            'seoauto_flush_events',
            'seoauto_poll_actions',
            'seoauto_sync_briefs',
            'seoauto_sync_users',
            'seoauto_cleanup',
            'seoauto_execute_action',
            'seoauto_retry_ack',
        ];

        if (self::hasActionScheduler() && function_exists('as_unschedule_all_actions')) {
            foreach ($hooks as $hook) {
                as_unschedule_all_actions($hook);
            }
        }

        foreach ($hooks as $hook) {
            $timestamp = wp_next_scheduled($hook);
            while ($timestamp !== false) {
                wp_unschedule_event($timestamp, $hook);
                $timestamp = wp_next_scheduled($hook);
            }
        }
    }

    public static function enqueueActionExecution(int $actionId, int $priority = 30): void
    {
        if (self::hasActionScheduler()) {
            as_enqueue_async_action(
                'seoauto_execute_action',
                ['action_id' => $actionId],
                'seo-automation-actions',
                true,
                $priority
            );
            return;
        }

        wp_schedule_single_event(time() + 1, 'seoauto_execute_action', [['action_id' => $actionId]]);
    }

    public static function scheduleAckRetry(int $actionId, int $delaySeconds = 60): void
    {
        if (self::hasActionScheduler()) {
            as_schedule_single_action(
                time() + $delaySeconds,
                'seoauto_retry_ack',
                ['action_id' => $actionId],
                'seo-automation-actions'
            );
            return;
        }

        wp_schedule_single_event(time() + $delaySeconds, 'seoauto_retry_ack', [['action_id' => $actionId]]);
    }

    private static function scheduleActionSchedulerJobs(): void
    {
        self::maybeScheduleAsRecurring('seoauto_flush_events', MINUTE_IN_SECONDS, 'seo-automation-events');
        self::maybeScheduleAsRecurring('seoauto_poll_actions', 5 * MINUTE_IN_SECONDS, 'seo-automation-actions');
        self::maybeScheduleAsRecurring('seoauto_sync_briefs', 10 * MINUTE_IN_SECONDS, 'seo-automation-sync');
        self::maybeScheduleAsRecurring('seoauto_sync_users', HOUR_IN_SECONDS, 'seo-automation-sync');

        if (!function_exists('as_has_scheduled_action') || !as_has_scheduled_action('seoauto_cleanup')) {
            as_schedule_recurring_action(
                strtotime('tomorrow 3am'),
                DAY_IN_SECONDS,
                'seoauto_cleanup',
                [],
                'seo-automation-housekeeping'
            );
        }
    }

    private static function scheduleWpCronJobs(): void
    {
        add_filter('cron_schedules', static function (array $schedules): array {
            if (!isset($schedules['seoauto_every_minute'])) {
                $schedules['seoauto_every_minute'] = [
                    'interval' => MINUTE_IN_SECONDS,
                    'display' => 'Every Minute (SEO Automation)',
                ];
            }

            if (!isset($schedules['seoauto_five_minutes'])) {
                $schedules['seoauto_five_minutes'] = [
                    'interval' => 5 * MINUTE_IN_SECONDS,
                    'display' => 'Every Five Minutes (SEO Automation)',
                ];
            }

            if (!isset($schedules['seoauto_ten_minutes'])) {
                $schedules['seoauto_ten_minutes'] = [
                    'interval' => 10 * MINUTE_IN_SECONDS,
                    'display' => 'Every Ten Minutes (SEO Automation)',
                ];
            }

            return $schedules;
        });

        if (!wp_next_scheduled('seoauto_flush_events')) {
            wp_schedule_event(time(), 'seoauto_every_minute', 'seoauto_flush_events');
        }

        if (!wp_next_scheduled('seoauto_poll_actions')) {
            wp_schedule_event(time(), 'seoauto_five_minutes', 'seoauto_poll_actions');
        }

        if (!wp_next_scheduled('seoauto_sync_briefs')) {
            wp_schedule_event(time(), 'seoauto_ten_minutes', 'seoauto_sync_briefs');
        }

        if (!wp_next_scheduled('seoauto_sync_users')) {
            wp_schedule_event(time(), 'hourly', 'seoauto_sync_users');
        }

        if (!wp_next_scheduled('seoauto_cleanup')) {
            wp_schedule_event(strtotime('tomorrow 3am'), 'daily', 'seoauto_cleanup');
        }
    }

    private static function maybeScheduleAsRecurring(string $hook, int $interval, string $group): void
    {
        if (function_exists('as_has_scheduled_action') && as_has_scheduled_action($hook)) {
            return;
        }

        as_schedule_recurring_action(time(), $interval, $hook, [], $group);
    }

    private function touchHeartbeat(): void
    {
        update_option('seoauto_last_cron_run', time(), false);
    }
}
