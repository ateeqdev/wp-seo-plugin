<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\Admin;

use SEOAutomation\Connector\API\LaravelClient;
use SEOAutomation\Connector\Sync\BriefSyncer;
use SEOAutomation\Connector\Sync\HealthChecker;
use SEOAutomation\Connector\Sync\SiteRegistrar;
use SEOAutomation\Connector\Utils\Logger;

final class MenuRegistrar
{
    private LaravelClient $client;

    private BriefSyncer $briefSyncer;

    private SiteRegistrar $siteRegistrar;

    private HealthChecker $healthChecker;

    private Logger $logger;

    public function __construct(
        LaravelClient $client,
        BriefSyncer $briefSyncer,
        SiteRegistrar $siteRegistrar,
        HealthChecker $healthChecker,
        Logger $logger
    )
    {
        $this->client = $client;
        $this->briefSyncer = $briefSyncer;
        $this->siteRegistrar = $siteRegistrar;
        $this->healthChecker = $healthChecker;
        $this->logger = $logger;
    }

    public function registerHooks(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_post_seoauto_register_site', [$this, 'handleRegisterSite']);
        add_action('admin_post_seoauto_health_check', [$this, 'handleHealthCheck']);
    }

    public function enqueueAssets(string $hookSuffix): void
    {
        if (strpos($hookSuffix, 'seoauto') === false) {
            return;
        }

        wp_enqueue_style(
            'seoauto-admin',
            SEOAUTO_PLUGIN_URL . 'assets/css/admin.css',
            [],
            SEOAUTO_VERSION
        );

        wp_enqueue_script(
            'seoauto-admin',
            SEOAUTO_PLUGIN_URL . 'assets/js/admin.js',
            [],
            SEOAUTO_VERSION,
            true
        );
    }

    public function handleRegisterSite(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('seoauto_register_site');
        $result = $this->siteRegistrar->registerOrUpdate();
        $ok = !isset($result['error']) && !empty($result['site_id']);

        $redirect = add_query_arg(
            [
                'page' => 'seoauto-settings',
                'seoauto_notice' => $ok ? 'register_ok' : 'register_failed',
            ],
            admin_url('admin.php')
        );
        wp_safe_redirect($redirect);
        exit;
    }

    public function handleHealthCheck(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('seoauto_health_check');
        $result = $this->healthChecker->check();
        $ok = !empty($result['connected']);

        $redirect = add_query_arg(
            [
                'page' => 'seoauto-settings',
                'seoauto_notice' => $ok ? 'health_ok' : 'health_failed',
            ],
            admin_url('admin.php')
        );
        wp_safe_redirect($redirect);
        exit;
    }

    public function registerMenu(): void
    {
        add_menu_page(
            'SEO Automation',
            'SEO Automation',
            'manage_options',
            'seoauto',
            [$this, 'renderDashboardPage'],
            'dashicons-performance',
            80
        );

        add_submenu_page('seoauto', 'Dashboard', 'Dashboard', 'manage_options', 'seoauto', [$this, 'renderDashboardPage']);
        add_submenu_page('seoauto', 'Execution Logs', 'Execution Logs', 'manage_options', 'seoauto-logs', [$this, 'renderLogsPage']);
        add_submenu_page('seoauto', 'Schedules', 'Schedules', 'manage_options', 'seoauto-schedules', [$this, 'renderSchedulesPage']);
        add_submenu_page('seoauto', 'Content Briefs', 'Content Briefs', 'edit_posts', 'seoauto-briefs', [$this, 'renderBriefsPage']);
        add_submenu_page('seoauto', 'Settings', 'Settings', 'manage_options', 'seoauto-settings', [$this, 'renderSettingsPage']);
    }

    public function registerSettings(): void
    {
        register_setting('seoauto_settings', 'seoauto_base_url', [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => '',
        ]);

        register_setting('seoauto_settings', 'seoauto_primary_seo_adapter', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'auto',
        ]);

        register_setting('seoauto_settings', 'seoauto_force_apply_non_auto', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => true,
        ]);

        register_setting('seoauto_settings', 'seoauto_debug_enabled', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false,
        ]);
    }

    public function renderDashboardPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $siteId = (int) get_option('seoauto_site_id', 0);
        $baseUrl = (string) get_option('seoauto_base_url', '');
        $lastUserSync = (int) get_option('seoauto_last_user_sync', 0);
        $lastBriefSync = (int) get_option('seoauto_last_brief_sync', 0);
        $lastCron = (int) get_option('seoauto_last_cron_run', 0);

        ?>
        <div class="wrap">
            <h1>SEO Automation Dashboard</h1>
            <table class="widefat striped" style="max-width:900px;">
                <tbody>
                    <tr><th scope="row">Laravel Base URL</th><td><?php echo esc_html($baseUrl !== '' ? $baseUrl : 'Not configured'); ?></td></tr>
                    <tr><th scope="row">Site ID</th><td><?php echo esc_html($siteId > 0 ? (string) $siteId : 'Not registered'); ?></td></tr>
                    <tr><th scope="row">Last User Sync</th><td><?php echo esc_html($lastUserSync > 0 ? wp_date('Y-m-d H:i:s', $lastUserSync) : 'Never'); ?></td></tr>
                    <tr><th scope="row">Last Brief Sync</th><td><?php echo esc_html($lastBriefSync > 0 ? wp_date('Y-m-d H:i:s', $lastBriefSync) : 'Never'); ?></td></tr>
                    <tr><th scope="row">Last Queue Heartbeat</th><td><?php echo esc_html($lastCron > 0 ? wp_date('Y-m-d H:i:s', $lastCron) : 'Never'); ?></td></tr>
                </tbody>
            </table>
            <p style="margin-top:16px;">
                <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=seoauto-settings')); ?>">Open Settings</a>
            </p>
        </div>
        <?php
    }

    public function renderLogsPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'seoauto_execution_logs';

        $severity = isset($_GET['severity']) ? sanitize_text_field((string) $_GET['severity']) : '';
        $dateFrom = isset($_GET['date_from']) ? sanitize_text_field((string) $_GET['date_from']) : '';
        $dateTo = isset($_GET['date_to']) ? sanitize_text_field((string) $_GET['date_to']) : '';
        $page = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $perPage = 50;
        $offset = ($page - 1) * $perPage;

        $where = ['1=1'];
        $params = [];

        if ($severity !== '' && in_array($severity, ['debug', 'info', 'warning', 'error'], true)) {
            $where[] = 'severity = %s';
            $params[] = $severity;
        }

        if ($dateFrom !== '') {
            $where[] = 'created_at >= %s';
            $params[] = $dateFrom . ' 00:00:00';
        }

        if ($dateTo !== '') {
            $where[] = 'created_at <= %s';
            $params[] = $dateTo . ' 23:59:59';
        }

        $whereSql = implode(' AND ', $where);

        $paramsForList = array_merge($params, [$perPage, $offset]);
        $listQuery = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$whereSql} ORDER BY created_at DESC LIMIT %d OFFSET %d",
            ...$paramsForList
        );
        $rows = $wpdb->get_results($listQuery); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

        $countQuery = empty($params)
            ? "SELECT COUNT(*) FROM {$table} WHERE {$whereSql}"
            : $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$whereSql}", ...$params);
        $total = (int) $wpdb->get_var($countQuery); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $totalPages = max(1, (int) ceil($total / $perPage));

        ?>
        <div class="wrap">
            <h1>Execution Logs</h1>
            <form method="get">
                <input type="hidden" name="page" value="seoauto-logs">
                <select name="severity">
                    <option value="">All Severities</option>
                    <option value="debug" <?php selected($severity, 'debug'); ?>>Debug</option>
                    <option value="info" <?php selected($severity, 'info'); ?>>Info</option>
                    <option value="warning" <?php selected($severity, 'warning'); ?>>Warning</option>
                    <option value="error" <?php selected($severity, 'error'); ?>>Error</option>
                </select>
                <input type="date" name="date_from" value="<?php echo esc_attr($dateFrom); ?>">
                <input type="date" name="date_to" value="<?php echo esc_attr($dateTo); ?>">
                <button class="button" type="submit">Filter</button>
            </form>

            <table class="wp-list-table widefat striped" style="margin-top:12px;">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Correlation</th>
                        <th>Event</th>
                        <th>Severity</th>
                        <th>Entity</th>
                        <th>Error</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($rows)) : ?>
                        <?php foreach ($rows as $row) : ?>
                            <tr>
                                <td><?php echo esc_html(wp_date('Y-m-d H:i:s', strtotime((string) $row->created_at))); ?></td>
                                <td><code><?php echo esc_html(substr((string) $row->correlation_id, 0, 8)); ?></code></td>
                                <td><?php echo esc_html((string) $row->event_name); ?></td>
                                <td><?php echo esc_html((string) $row->severity); ?></td>
                                <td><?php echo esc_html((string) $row->entity_type . ':' . (string) $row->entity_id); ?></td>
                                <td><?php echo esc_html((string) $row->error_message); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="6">No logs found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    echo wp_kses_post(
                        paginate_links([
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'current' => $page,
                            'total' => $totalPages,
                        ])
                    );
                    ?>
                </div>
            </div>
        </div>
        <?php
    }

    public function renderSchedulesPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $tasks = [];
        $scheduled = [];

        try {
            $tasksRes = $this->client->listTasks();
            $scheduledRes = $this->client->listScheduledTasks(['limit' => 50]);
            $tasks = isset($tasksRes['tasks']) && is_array($tasksRes['tasks']) ? $tasksRes['tasks'] : [];
            $scheduled = isset($scheduledRes['scheduled_tasks']) && is_array($scheduledRes['scheduled_tasks']) ? $scheduledRes['scheduled_tasks'] : [];
        } catch (\Throwable $exception) {
            $this->logger->warning('admin_schedule_fetch_failed', ['error' => $exception->getMessage()], 'admin');
        }

        ?>
        <div class="wrap">
            <h1>Schedules</h1>

            <h2>Configured Tasks</h2>
            <table class="wp-list-table widefat striped">
                <thead><tr><th>Name</th><th>Category</th><th>Frequency</th><th>Enabled</th><th>Timezone</th></tr></thead>
                <tbody>
                <?php if (!empty($tasks)) : ?>
                    <?php foreach ($tasks as $task) : ?>
                        <tr>
                            <td><?php echo esc_html((string) ($task['name'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($task['category'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($task['frequency'] ?? '')); ?></td>
                            <td><?php echo !empty($task['is_enabled']) ? 'Yes' : 'No'; ?></td>
                            <td><?php echo esc_html((string) ($task['run_timezone'] ?? 'UTC')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr><td colspan="5">No tasks found or API unavailable.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>

            <h2 style="margin-top:24px;">Scheduled Runs</h2>
            <table class="wp-list-table widefat striped">
                <thead><tr><th>ID</th><th>Task</th><th>Status</th><th>Trigger</th><th>Scheduled For</th></tr></thead>
                <tbody>
                <?php if (!empty($scheduled)) : ?>
                    <?php foreach ($scheduled as $item) : ?>
                        <tr>
                            <td><?php echo esc_html((string) ($item['id'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($item['task_name'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($item['status'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($item['trigger_source'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($item['scheduled_for'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr><td colspan="5">No scheduled tasks found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function renderBriefsPage(): void
    {
        if (!current_user_can('edit_posts')) {
            wp_die('Unauthorized');
        }

        $this->briefSyncer->sync();

        global $wpdb;
        $table = $wpdb->prefix . 'seoauto_content_briefs';

        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            "SELECT * FROM {$table} ORDER BY synced_at DESC LIMIT 100"
        );

        ?>
        <div class="wrap">
            <h1>Content Briefs</h1>
            <table class="wp-list-table widefat striped">
                <thead><tr><th>ID</th><th>Title</th><th>Focus Keyword</th><th>Article Status</th><th>Assignment</th><th>Linked Post</th></tr></thead>
                <tbody>
                    <?php if (!empty($rows)) : ?>
                        <?php foreach ($rows as $row) : ?>
                            <?php $payload = json_decode((string) $row->payload, true); ?>
                            <tr>
                                <td><?php echo esc_html((string) $row->laravel_content_brief_id); ?></td>
                                <td><?php echo esc_html((string) ($payload['brief_title'] ?? 'Untitled')); ?></td>
                                <td><?php echo esc_html((string) ($payload['focus_keyword'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) $row->article_status); ?></td>
                                <td><?php echo esc_html((string) $row->assignment_status); ?></td>
                                <td>
                                    <?php if (!empty($row->linked_wp_post_id)) : ?>
                                        <a href="<?php echo esc_url(get_edit_post_link((int) $row->linked_wp_post_id)); ?>">#<?php echo esc_html((string) $row->linked_wp_post_id); ?></a>
                                    <?php else : ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="6">No briefs synced yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $notice = isset($_GET['seoauto_notice']) ? sanitize_text_field((string) $_GET['seoauto_notice']) : '';

        ?>
        <div class="wrap">
            <h1>SEO Automation Settings</h1>
            <?php if ($notice === 'register_ok') : ?>
                <div class="notice notice-success"><p>Site registration updated successfully.</p></div>
            <?php elseif ($notice === 'register_failed') : ?>
                <div class="notice notice-error"><p>Site registration failed. Check logs for details.</p></div>
            <?php elseif ($notice === 'health_ok') : ?>
                <div class="notice notice-success"><p>Health check passed.</p></div>
            <?php elseif ($notice === 'health_failed') : ?>
                <div class="notice notice-warning"><p>Health check failed.</p></div>
            <?php endif; ?>
            <form method="post" action="options.php">
                <?php settings_fields('seoauto_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="seoauto_base_url">Laravel Base URL</label></th>
                        <td>
                            <input type="url" class="regular-text" id="seoauto_base_url" name="seoauto_base_url" value="<?php echo esc_attr((string) get_option('seoauto_base_url', '')); ?>" placeholder="https://api.example.com">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="seoauto_primary_seo_adapter">Primary SEO Adapter</label></th>
                        <td>
                            <?php $adapter = (string) get_option('seoauto_primary_seo_adapter', 'auto'); ?>
                            <select name="seoauto_primary_seo_adapter" id="seoauto_primary_seo_adapter">
                                <option value="auto" <?php selected($adapter, 'auto'); ?>>Auto Detect</option>
                                <option value="yoast" <?php selected($adapter, 'yoast'); ?>>Yoast</option>
                                <option value="rankmath" <?php selected($adapter, 'rankmath'); ?>>Rank Math</option>
                                <option value="aioseo" <?php selected($adapter, 'aioseo'); ?>>AIOSEO</option>
                                <option value="core" <?php selected($adapter, 'core'); ?>>Core Fallback</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Force Apply Non-Auto Actions</th>
                        <td>
                            <label>
                                <input type="checkbox" name="seoauto_force_apply_non_auto" value="1" <?php checked((bool) get_option('seoauto_force_apply_non_auto', true)); ?>>
                                Enabled
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Debug Logging</th>
                        <td>
                            <label>
                                <input type="checkbox" name="seoauto_debug_enabled" value="1" <?php checked((bool) get_option('seoauto_debug_enabled', false)); ?>>
                                Enabled
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save Settings'); ?>
            </form>

            <hr>
            <h2>Connection</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:12px;">
                <?php wp_nonce_field('seoauto_register_site'); ?>
                <input type="hidden" name="action" value="seoauto_register_site">
                <button type="submit" class="button button-primary">Register/Update Site</button>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                <?php wp_nonce_field('seoauto_health_check'); ?>
                <input type="hidden" name="action" value="seoauto_health_check">
                <button type="submit" class="button">Run Health Check</button>
            </form>
        </div>
        <?php
    }
}
