<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\Admin;

use SEOAutomation\Connector\API\LaravelClient;
use SEOAutomation\Connector\Auth\OAuthHandler;
use SEOAutomation\Connector\Auth\SiteTokenManager;
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

    private OAuthHandler $oauthHandler;

    private SiteTokenManager $tokenManager;

    private Logger $logger;

    public function __construct(
        LaravelClient $client,
        BriefSyncer $briefSyncer,
        SiteRegistrar $siteRegistrar,
        HealthChecker $healthChecker,
        OAuthHandler $oauthHandler,
        SiteTokenManager $tokenManager,
        Logger $logger
    )
    {
        $this->client = $client;
        $this->briefSyncer = $briefSyncer;
        $this->siteRegistrar = $siteRegistrar;
        $this->healthChecker = $healthChecker;
        $this->oauthHandler = $oauthHandler;
        $this->tokenManager = $tokenManager;
        $this->logger = $logger;
    }

    public function registerHooks(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_post_seoauto_register_site', [$this, 'handleRegisterSite']);
        add_action('admin_post_seoauto_health_check', [$this, 'handleHealthCheck']);
        add_action('admin_post_seoauto_start_oauth', [$this, 'handleStartOAuth']);
        add_action('admin_post_seoauto_revoke_oauth', [$this, 'handleRevokeOAuth']);
        add_action('admin_post_seoauto_rotate_token', [$this, 'handleRotateToken']);
        add_action('admin_post_seoauto_update_site_profile', [$this, 'handleUpdateSiteProfile']);
        add_action('admin_post_seoauto_update_task', [$this, 'handleUpdateTask']);
        add_action('admin_post_seoauto_schedule_task', [$this, 'handleScheduleTask']);
        add_action('admin_post_seoauto_link_brief', [$this, 'handleLinkBrief']);
        add_action('admin_post_seoauto_dispatch_action', [$this, 'handleDispatchAction']);
        add_action('admin_post_seoauto_delete_logs', [$this, 'handleDeleteLogs']);
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
        $baseUrl = trim((string) get_option('seoauto_base_url', ''));
        if ($baseUrl === '' || !$this->isBaseUrlSyntaxValid($baseUrl)) {
            wp_safe_redirect(add_query_arg([
                'page' => 'seoauto-settings',
                'seoauto_notice' => 'register_missing_base_url',
            ], admin_url('admin.php')));
            exit;
        }

        $result = $this->siteRegistrar->registerOrUpdate(true);
        $ok = !isset($result['error']) && (!empty($result['site_id']) || ((int) get_option('seoauto_site_id', 0) > 0));

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

    public function handleStartOAuth(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('seoauto_start_oauth');

        try {
            $oauthUrl = $this->oauthHandler->beginGoogleOAuth(['search_console', 'analytics']);

            if ($oauthUrl === '') {
                throw new \RuntimeException('Missing oauth_url');
            }

            wp_redirect($oauthUrl);
            exit;
        } catch (\Throwable $exception) {
            update_option('seoauto_oauth_status', 'failed', false);
            update_option('seoauto_oauth_last_error', $exception->getMessage(), false);

            $redirect = add_query_arg(
                [
                    'page' => 'seoauto-settings',
                    'seoauto_notice' => 'oauth_init_failed',
                ],
                admin_url('admin.php')
            );
            wp_safe_redirect($redirect);
            exit;
        }
    }

    public function handleRevokeOAuth(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('seoauto_revoke_oauth');

        try {
            $reason = isset($_POST['revocation_reason']) ? sanitize_text_field((string) $_POST['revocation_reason']) : '';
            $this->client->revokeGoogleOAuth($reason !== '' ? ['revocation_reason' => $reason] : []);

            update_option('seoauto_oauth_status', 'pending', false);
            update_option('seoauto_oauth_provider', '', false);
            update_option('seoauto_oauth_scopes', [], false);
            update_option('seoauto_oauth_connected_at', 0, false);
            update_option('seoauto_oauth_last_error', '', false);

            $notice = 'oauth_revoke_ok';
        } catch (\Throwable $exception) {
            if ((int) $exception->getCode() === 404) {
                update_option('seoauto_oauth_status', 'pending', false);
                update_option('seoauto_oauth_provider', '', false);
                update_option('seoauto_oauth_scopes', [], false);
                update_option('seoauto_oauth_connected_at', 0, false);
                update_option('seoauto_oauth_last_error', '', false);
                $notice = 'oauth_revoke_ok';
            } else {
                update_option('seoauto_oauth_last_error', $exception->getMessage(), false);
                $notice = 'oauth_revoke_failed';
            }
        }

        wp_safe_redirect(add_query_arg([
            'page' => 'seoauto-settings',
            'seoauto_notice' => $notice,
        ], admin_url('admin.php')));
        exit;
    }

    public function handleRotateToken(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('seoauto_rotate_token');
        $siteId = (int) get_option('seoauto_site_id', 0);

        if ($siteId <= 0) {
            wp_safe_redirect(add_query_arg([
                'page' => 'seoauto-settings',
                'seoauto_notice' => 'rotate_failed',
            ], admin_url('admin.php')));
            exit;
        }

        try {
            $response = $this->client->rotateSiteToken($siteId);
            $newToken = isset($response['api_key']) ? (string) $response['api_key'] : '';

            if ($newToken === '') {
                throw new \RuntimeException('Token rotation response missing api_key.');
            }

            $this->tokenManager->storeToken($newToken);
            update_option('seoauto_oauth_last_error', '', false);
            $notice = 'rotate_ok';
        } catch (\Throwable $exception) {
            update_option('seoauto_oauth_last_error', $exception->getMessage(), false);
            $notice = 'rotate_failed';
        }

        wp_safe_redirect(add_query_arg([
            'page' => 'seoauto-settings',
            'seoauto_notice' => $notice,
        ], admin_url('admin.php')));
        exit;
    }

    public function handleUpdateSiteProfile(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('seoauto_update_site_profile');
        $siteId = (int) get_option('seoauto_site_id', 0);

        $platformUserId = isset($_POST['platform_user_id']) ? sanitize_text_field((string) $_POST['platform_user_id']) : '';
        $description = isset($_POST['site_description']) ? sanitize_textarea_field((string) $_POST['site_description']) : '';
        $taste = isset($_POST['site_taste']) ? sanitize_textarea_field((string) $_POST['site_taste']) : '';

        update_option('seoauto_owner_platform_user_id', $platformUserId, false);
        update_option('seoauto_site_profile_description', $description, false);
        update_option('seoauto_site_profile_taste', $taste, false);

        if ($siteId <= 0 || $platformUserId === '') {
            wp_safe_redirect(add_query_arg([
                'page' => 'seoauto-settings',
                'seoauto_notice' => 'profile_failed',
            ], admin_url('admin.php')));
            exit;
        }

        try {
            $this->client->updateSiteProfile($siteId, [
                'platform_user_id' => $platformUserId,
                'description' => $description,
                'taste' => $taste,
            ]);
            $notice = 'profile_ok';
        } catch (\Throwable $exception) {
            update_option('seoauto_oauth_last_error', $exception->getMessage(), false);
            $notice = 'profile_failed';
        }

        wp_safe_redirect(add_query_arg([
            'page' => 'seoauto-settings',
            'seoauto_notice' => $notice,
        ], admin_url('admin.php')));
        exit;
    }

    public function handleUpdateTask(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('seoauto_update_task');
        $taskId = isset($_POST['task_id']) ? (int) $_POST['task_id'] : 0;

        if ($taskId <= 0) {
            wp_safe_redirect(add_query_arg([
                'page' => 'seoauto-schedules',
                'seoauto_notice' => 'task_update_failed',
            ], admin_url('admin.php')));
            exit;
        }

        try {
            $isEnabled = !empty($_POST['is_enabled']);
            $delayMinutes = isset($_POST['delay_minutes']) ? max(0, (int) $_POST['delay_minutes']) : 0;

            $this->client->updateTaskConfig($taskId, [
                'is_enabled' => $isEnabled,
                'delay_minutes' => $delayMinutes,
            ]);
            $notice = 'task_update_ok';
        } catch (\Throwable $exception) {
            $this->logger->warning('admin_task_update_failed', ['error' => $exception->getMessage()], 'admin');
            $notice = 'task_update_failed';
        }

        wp_safe_redirect(add_query_arg([
            'page' => 'seoauto-schedules',
            'seoauto_notice' => $notice,
        ], admin_url('admin.php')));
        exit;
    }

    public function handleScheduleTask(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('seoauto_schedule_task');
        $taskId = isset($_POST['task_id']) ? (int) $_POST['task_id'] : 0;

        if ($taskId <= 0) {
            wp_safe_redirect(add_query_arg([
                'page' => 'seoauto-schedules',
                'seoauto_notice' => 'task_schedule_failed',
            ], admin_url('admin.php')));
            exit;
        }

        $payload = [];
        $scheduledFor = isset($_POST['scheduled_for']) ? sanitize_text_field((string) $_POST['scheduled_for']) : '';
        if ($scheduledFor !== '') {
            $timestamp = strtotime($scheduledFor);
            if ($timestamp !== false) {
                $payload['scheduled_for'] = gmdate('c', $timestamp);
            }
        }

        $inputJson = isset($_POST['input_params_json']) ? trim((string) $_POST['input_params_json']) : '';
        if ($inputJson !== '') {
            $decoded = json_decode($inputJson, true);
            if (is_array($decoded)) {
                $payload['input_params'] = $decoded;
            }
        }

        try {
            $this->client->scheduleTask($taskId, $payload);
            $notice = 'task_schedule_ok';
        } catch (\Throwable $exception) {
            $this->logger->warning('admin_task_schedule_failed', ['error' => $exception->getMessage()], 'admin');
            $notice = 'task_schedule_failed';
        }

        wp_safe_redirect(add_query_arg([
            'page' => 'seoauto-schedules',
            'seoauto_notice' => $notice,
        ], admin_url('admin.php')));
        exit;
    }

    public function handleLinkBrief(): void
    {
        if (!current_user_can('edit_posts')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('seoauto_link_brief');

        $briefId = isset($_POST['brief_id']) ? (int) $_POST['brief_id'] : 0;
        $postId = isset($_POST['wp_post_id']) ? (int) $_POST['wp_post_id'] : 0;
        $articleStatus = isset($_POST['article_status']) ? sanitize_text_field((string) $_POST['article_status']) : 'drafted';
        if (!in_array($articleStatus, ['drafted', 'published'], true)) {
            $articleStatus = 'drafted';
        }
        $siteId = (int) get_option('seoauto_site_id', 0);

        if ($briefId <= 0 || $postId <= 0 || $siteId <= 0) {
            wp_safe_redirect(add_query_arg([
                'page' => 'seoauto-briefs',
                'seoauto_notice' => 'brief_link_failed',
            ], admin_url('admin.php')));
            exit;
        }

        $post = get_post($postId);
        if (!$post instanceof \WP_Post) {
            wp_safe_redirect(add_query_arg([
                'page' => 'seoauto-briefs',
                'seoauto_notice' => 'brief_link_failed',
            ], admin_url('admin.php')));
            exit;
        }

        try {
            $payload = [
                'wp_post_id' => $postId,
                'wp_post_url' => get_permalink($postId),
                'wp_post_title' => get_the_title($postId),
                'article_status' => $articleStatus,
                'published_at' => get_post_status($postId) === 'publish' ? gmdate('c', (int) get_post_time('U', true, $postId)) : null,
            ];

            $this->client->linkArticleToBrief($siteId, $briefId, $payload);
            $this->updateLocalBriefLinkState($briefId, $postId, (string) get_permalink($postId), $articleStatus);
            $notice = 'brief_link_ok';
        } catch (\Throwable $exception) {
            $this->logger->warning('admin_brief_link_failed', ['error' => $exception->getMessage()], 'admin');
            $notice = 'brief_link_failed';
        }

        wp_safe_redirect(add_query_arg([
            'page' => 'seoauto-briefs',
            'seoauto_notice' => $notice,
        ], admin_url('admin.php')));
        exit;
    }

    public function handleDispatchAction(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('seoauto_dispatch_action');

        $siteId = (int) get_option('seoauto_site_id', 0);
        $actionId = isset($_POST['dispatch_action_id']) ? (int) $_POST['dispatch_action_id'] : 0;

        if ($siteId <= 0 || $actionId <= 0) {
            wp_safe_redirect(add_query_arg([
                'page' => 'seoauto-settings',
                'seoauto_notice' => 'dispatch_failed',
            ], admin_url('admin.php')));
            exit;
        }

        try {
            $this->client->dispatchAction($siteId, $actionId);
            $notice = 'dispatch_ok';
        } catch (\Throwable $exception) {
            $this->logger->warning('admin_dispatch_action_failed', ['error' => $exception->getMessage()], 'admin');
            $notice = 'dispatch_failed';
        }

        wp_safe_redirect(add_query_arg([
            'page' => 'seoauto-settings',
            'seoauto_notice' => $notice,
        ], admin_url('admin.php')));
        exit;
    }

    public function handleDeleteLogs(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('seoauto_delete_logs');

        global $wpdb;
        $table = $wpdb->prefix . 'seoauto_execution_logs';
        $deleted = $wpdb->query("DELETE FROM {$table}"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

        $notice = $deleted === false ? 'logs_delete_failed' : 'logs_delete_ok';
        $deletedCount = $deleted === false ? 0 : (int) $deleted;

        wp_safe_redirect(add_query_arg([
            'page' => 'seoauto-logs',
            'seoauto_notice' => $notice,
            'deleted_count' => $deletedCount,
        ], admin_url('admin.php')));
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
        add_submenu_page('seoauto', 'OAuth Callback', 'OAuth Callback', 'manage_options', 'seoauto-oauth-callback', [$this, 'renderOauthCallbackPage']);
        // Backward-compatible callback slug kept accessible but hidden from sidebar menu.
        add_submenu_page(null, 'OAuth Callback', 'OAuth Callback', 'manage_options', 'seo-platform-oauth-complete', [$this, 'renderOauthCallbackPage']);
    }

    public function registerSettings(): void
    {
        register_setting('seoauto_settings', 'seoauto_base_url', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitizeBaseUrl'],
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

        register_setting('seoauto_settings', 'seoauto_allow_insecure_ssl', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false,
        ]);

        register_setting('seoauto_settings', 'seoauto_owner_platform_user_id', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '1',
        ]);

        register_setting('seoauto_settings', 'seoauto_site_profile_description', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default' => '',
        ]);

        register_setting('seoauto_settings', 'seoauto_site_profile_taste', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default' => '',
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

        $notice = isset($_GET['seoauto_notice']) ? sanitize_text_field((string) $_GET['seoauto_notice']) : '';
        $deletedCount = isset($_GET['deleted_count']) ? max(0, (int) $_GET['deleted_count']) : 0;
        $pageNo = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $status = isset($_GET['status']) ? sanitize_text_field((string) $_GET['status']) : '';
        $taskId = isset($_GET['seo_execution_task_id']) ? max(0, (int) $_GET['seo_execution_task_id']) : 0;
        $startedFrom = isset($_GET['started_from']) ? sanitize_text_field((string) $_GET['started_from']) : '';
        $startedTo = isset($_GET['started_to']) ? sanitize_text_field((string) $_GET['started_to']) : '';
        $perPage = 20;

        $query = [
            'per_page' => $perPage,
            'page' => $pageNo,
        ];

        if ($status !== '') {
            $query['status'] = $status;
        }
        if ($taskId > 0) {
            $query['seo_execution_task_id'] = $taskId;
        }
        if ($startedFrom !== '') {
            $query['started_from'] = $startedFrom;
        }
        if ($startedTo !== '') {
            $query['started_to'] = $startedTo;
        }

        $remote = null;
        $remoteError = '';

        try {
            $remote = $this->client->listExecutionLogsFast($query);
        } catch (\Throwable $exception) {
            $remoteError = $exception->getMessage();
        }

        if (is_array($remote) && isset($remote['execution_logs']) && is_array($remote['execution_logs'])) {
            $logs = $remote['execution_logs'];
            $current = (int) ($remote['current_page'] ?? $pageNo);
            $last = (int) ($remote['last_page'] ?? $pageNo);
            ?>
            <div class="wrap">
                <h1>Execution Logs</h1>
                <?php $this->renderLogsToolbar($notice, $deletedCount); ?>
                <form method="get">
                    <input type="hidden" name="page" value="seoauto-logs">
                    <select name="status">
                        <option value="">All Statuses</option>
                        <option value="queued" <?php selected($status, 'queued'); ?>>Queued</option>
                        <option value="running" <?php selected($status, 'running'); ?>>Running</option>
                        <option value="completed" <?php selected($status, 'completed'); ?>>Completed</option>
                        <option value="failed" <?php selected($status, 'failed'); ?>>Failed</option>
                    </select>
                    <input type="number" name="seo_execution_task_id" min="0" placeholder="Task ID" value="<?php echo esc_attr($taskId > 0 ? (string) $taskId : ''); ?>">
                    <input type="datetime-local" name="started_from" value="<?php echo esc_attr($startedFrom); ?>">
                    <input type="datetime-local" name="started_to" value="<?php echo esc_attr($startedTo); ?>">
                    <button class="button" type="submit">Filter</button>
                </form>

                <table class="wp-list-table widefat striped" style="margin-top:12px;">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Job ID</th>
                            <th>Status</th>
                            <th>Progress</th>
                            <th>Task</th>
                            <th>Started</th>
                            <th>Completed</th>
                            <th>Duration (s)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($logs)) : ?>
                            <?php foreach ($logs as $log) : ?>
                                <?php if (!is_array($log)) { continue; } ?>
                                <tr>
                                    <td><?php echo esc_html((string) ($log['id'] ?? '')); ?></td>
                                    <td><code><?php echo esc_html((string) ($log['job_id'] ?? '')); ?></code></td>
                                    <td><?php echo esc_html((string) ($log['status'] ?? '')); ?></td>
                                    <td><?php echo esc_html((string) ($log['progress'] ?? '')); ?></td>
                                    <td><?php echo esc_html((string) (($log['execution_task']['name'] ?? ''))); ?></td>
                                    <td><?php echo esc_html((string) ($log['started_at'] ?? '')); ?></td>
                                    <td><?php echo esc_html((string) ($log['completed_at'] ?? '')); ?></td>
                                    <td><?php echo esc_html((string) ($log['duration_seconds'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr><td colspan="8">No remote execution logs found.</td></tr>
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
                                'current' => $current,
                                'total' => max(1, $last),
                            ])
                        );
                        ?>
                    </div>
                </div>
            </div>
            <?php
            return;
        }

        $this->renderLocalLogsFallback($remoteError, $notice, $deletedCount);
    }

    private function renderLocalLogsFallback(string $remoteError = '', string $notice = '', int $deletedCount = 0): void
    {
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
            <?php $this->renderLogsToolbar($notice, $deletedCount); ?>
            <?php if ($remoteError !== '') : ?>
                <div class="notice notice-warning"><p>Remote execution logs unavailable. Showing local logs. <?php echo esc_html($remoteError); ?></p></div>
            <?php endif; ?>
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
                    <tr><th>Time</th><th>Correlation</th><th>Event</th><th>Severity</th><th>Entity</th><th>Error</th></tr>
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

    private function renderLogsToolbar(string $notice, int $deletedCount): void
    {
        if ($notice === 'logs_delete_ok') {
            ?>
            <div class="notice notice-success"><p><?php echo esc_html(sprintf('Deleted %d local execution log entries.', $deletedCount)); ?></p></div>
            <?php
        } elseif ($notice === 'logs_delete_failed') {
            ?>
            <div class="notice notice-error"><p>Failed to delete local execution logs.</p></div>
            <?php
        }
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:12px;">
            <?php wp_nonce_field('seoauto_delete_logs'); ?>
            <input type="hidden" name="action" value="seoauto_delete_logs">
            <button type="submit" class="button" onclick="return confirm('Delete all local execution logs?');">Delete Local Logs</button>
        </form>
        <?php
    }

    public function renderSchedulesPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $notice = isset($_GET['seoauto_notice']) ? sanitize_text_field((string) $_GET['seoauto_notice']) : '';
        $tasks = [];
        $scheduled = [];
        $remoteErrors = [];

        try {
            $tasksRes = $this->client->listTasksFast();
            $tasks = isset($tasksRes['tasks']) && is_array($tasksRes['tasks']) ? $tasksRes['tasks'] : [];
        } catch (\Throwable $exception) {
            $remoteErrors[] = 'Tasks: ' . $exception->getMessage();
            $this->logger->warning('admin_tasks_fetch_failed', ['error' => $exception->getMessage()], 'admin');
        }

        try {
            $scheduledRes = $this->client->listScheduledTasksFast(['limit' => 50]);
            $scheduled = isset($scheduledRes['scheduled_tasks']) && is_array($scheduledRes['scheduled_tasks']) ? $scheduledRes['scheduled_tasks'] : [];
        } catch (\Throwable $exception) {
            $remoteErrors[] = 'Scheduled runs: ' . $exception->getMessage();
            $this->logger->warning('admin_scheduled_runs_fetch_failed', ['error' => $exception->getMessage()], 'admin');
        }

        ?>
        <div class="wrap">
            <h1>Schedules</h1>
            <?php if ($notice === 'task_update_ok') : ?>
                <div class="notice notice-success"><p>Task configuration updated.</p></div>
            <?php elseif ($notice === 'task_update_failed') : ?>
                <div class="notice notice-error"><p>Task configuration update failed.</p></div>
            <?php elseif ($notice === 'task_schedule_ok') : ?>
                <div class="notice notice-success"><p>Task scheduled successfully.</p></div>
            <?php elseif ($notice === 'task_schedule_failed') : ?>
                <div class="notice notice-error"><p>Task scheduling failed.</p></div>
            <?php endif; ?>
            <?php if (!empty($remoteErrors)) : ?>
                <div class="notice notice-warning">
                    <p>Some schedule data could not be loaded from Laravel. <?php echo esc_html(implode(' | ', $remoteErrors)); ?></p>
                </div>
            <?php endif; ?>

            <h2>Configured Tasks</h2>
            <table class="wp-list-table widefat striped">
                <thead><tr><th>Task ID</th><th>Name</th><th>Category</th><th>Frequency</th><th>Enabled</th><th>Timezone</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if (!empty($tasks)) : ?>
                    <?php foreach ($tasks as $task) : ?>
                        <?php
                        $taskId = isset($task['seo_execution_task_id']) ? (int) $task['seo_execution_task_id'] : 0;
                        $isEnabled = !empty($task['is_enabled']);
                        $delay = isset($task['delay_minutes']) ? (int) $task['delay_minutes'] : 0;
                        ?>
                        <tr>
                            <td><?php echo esc_html((string) $taskId); ?></td>
                            <td><?php echo esc_html((string) ($task['name'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($task['category'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($task['frequency'] ?? '')); ?></td>
                            <td><?php echo $isEnabled ? 'Yes' : 'No'; ?></td>
                            <td><?php echo esc_html((string) ($task['run_timezone'] ?? 'UTC')); ?></td>
                            <td>
                                <?php if ($taskId > 0) : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:8px;">
                                        <?php wp_nonce_field('seoauto_update_task'); ?>
                                        <input type="hidden" name="action" value="seoauto_update_task">
                                        <input type="hidden" name="task_id" value="<?php echo esc_attr((string) $taskId); ?>">
                                        <label style="display:inline-block;margin-right:6px;">
                                            <input type="checkbox" name="is_enabled" value="1" <?php checked($isEnabled); ?>> Enabled
                                        </label>
                                        <label style="display:inline-block;margin-right:6px;">
                                            Delay
                                            <input type="number" min="0" name="delay_minutes" value="<?php echo esc_attr((string) $delay); ?>" style="width:70px;">
                                        </label>
                                        <button type="submit" class="button button-small">Save</button>
                                    </form>

                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <?php wp_nonce_field('seoauto_schedule_task'); ?>
                                        <input type="hidden" name="action" value="seoauto_schedule_task">
                                        <input type="hidden" name="task_id" value="<?php echo esc_attr((string) $taskId); ?>">
                                        <input type="datetime-local" name="scheduled_for" style="margin-right:6px;">
                                        <input type="text" name="input_params_json" placeholder='{\"origin\":\"wp-admin\"}' style="width:180px;margin-right:6px;">
                                        <button type="submit" class="button button-small">Schedule</button>
                                    </form>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr><td colspan="7">No tasks found or API unavailable.</td></tr>
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

        $notice = isset($_GET['seoauto_notice']) ? sanitize_text_field((string) $_GET['seoauto_notice']) : '';
        $this->briefSyncer->sync();

        global $wpdb;
        $table = $wpdb->prefix . 'seoauto_content_briefs';

        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            "SELECT * FROM {$table} ORDER BY synced_at DESC LIMIT 100"
        );

        ?>
        <div class="wrap">
            <h1>Content Briefs</h1>
            <?php if ($notice === 'brief_link_ok') : ?>
                <div class="notice notice-success"><p>Content brief linked to article.</p></div>
            <?php elseif ($notice === 'brief_link_failed') : ?>
                <div class="notice notice-error"><p>Failed to link content brief.</p></div>
            <?php endif; ?>
            <table class="wp-list-table widefat striped">
                <thead><tr><th>ID</th><th>Title</th><th>Focus Keyword</th><th>Article Status</th><th>Assignment</th><th>Linked Post</th><th>Link Article</th></tr></thead>
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
                                <td>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <?php wp_nonce_field('seoauto_link_brief'); ?>
                                        <input type="hidden" name="action" value="seoauto_link_brief">
                                        <input type="hidden" name="brief_id" value="<?php echo esc_attr((string) $row->laravel_content_brief_id); ?>">
                                        <input type="number" min="1" name="wp_post_id" placeholder="WP Post ID" style="width:110px;margin-right:6px;">
                                        <select name="article_status" style="margin-right:6px;">
                                            <option value="drafted">drafted</option>
                                            <option value="published">published</option>
                                        </select>
                                        <button class="button button-small" type="submit">Link</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="7">No briefs synced yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function renderOauthCallbackPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $hasCallbackState = isset($_GET['status']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ($hasCallbackState) {
            $result = $this->oauthHandler->handleCallback($_GET); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $status = sanitize_text_field((string) ($result['status'] ?? 'failed'));
            $provider = sanitize_text_field((string) ($result['provider'] ?? 'google'));
            $scopes = isset($result['scopes']) && is_array($result['scopes']) ? $result['scopes'] : [];
            $error = sanitize_text_field((string) ($result['error'] ?? ''));
            $health = isset($result['health']) && is_array($result['health']) ? $result['health'] : [];
        } else {
            $status = (string) get_option('seoauto_oauth_status', 'pending');
            $provider = (string) get_option('seoauto_oauth_provider', '');
            $scopes = get_option('seoauto_oauth_scopes', []);
            if (!is_array($scopes)) {
                $scopes = [];
            }
            $error = (string) get_option('seoauto_oauth_last_error', '');
            $health = [];
        }

        ?>
        <div class="wrap">
            <h1>OAuth Connection</h1>
            <?php if ($status === 'active') : ?>
                <div class="notice notice-success"><p>OAuth connected successfully.</p></div>
            <?php elseif ($status === 'error') : ?>
                <div class="notice notice-warning"><p>OAuth succeeded but health check failed.</p></div>
            <?php elseif ($status === 'pending' || $status === 'in_progress') : ?>
                <div class="notice notice-info"><p>OAuth not connected yet.</p></div>
            <?php else : ?>
                <div class="notice notice-error"><p>OAuth callback failed.</p></div>
            <?php endif; ?>

            <table class="widefat striped" style="max-width:900px;">
                <tbody>
                    <tr><th scope="row">Status</th><td><?php echo esc_html($status); ?></td></tr>
                    <tr><th scope="row">Provider</th><td><?php echo esc_html($provider); ?></td></tr>
                    <tr><th scope="row">Scopes</th><td><?php echo esc_html(!empty($scopes) ? implode(', ', array_map('strval', $scopes)) : 'None'); ?></td></tr>
                    <tr><th scope="row">Error</th><td><?php echo esc_html($error !== '' ? $error : 'None'); ?></td></tr>
                    <tr><th scope="row">Health Connected</th><td><?php echo !empty($health['connected']) ? 'Yes' : 'No'; ?></td></tr>
                </tbody>
            </table>

            <p style="margin-top:16px;">
                <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=seoauto-settings')); ?>">Back to Settings</a>
            </p>
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
            <?php settings_errors('seoauto_base_url'); ?>
            <?php if ($notice === 'register_ok') : ?>
                <div class="notice notice-success"><p>Site registration updated successfully.</p></div>
            <?php elseif ($notice === 'register_missing_base_url') : ?>
                <div class="notice notice-warning"><p>Set Laravel Base URL first, then click Register/Update Site.</p></div>
            <?php elseif ($notice === 'register_failed') : ?>
                <div class="notice notice-error"><p>Site registration failed. Check logs for details.</p></div>
            <?php elseif ($notice === 'health_ok') : ?>
                <div class="notice notice-success"><p>Health check passed.</p></div>
            <?php elseif ($notice === 'health_failed') : ?>
                <div class="notice notice-warning"><p>Health check failed.</p></div>
            <?php elseif ($notice === 'oauth_init_failed') : ?>
                <div class="notice notice-error"><p>Failed to initialize OAuth. Check API settings and registration.</p></div>
            <?php elseif ($notice === 'oauth_revoke_ok') : ?>
                <div class="notice notice-success"><p>OAuth token revoked successfully.</p></div>
            <?php elseif ($notice === 'oauth_revoke_failed') : ?>
                <div class="notice notice-error"><p>OAuth revoke failed.</p></div>
            <?php elseif ($notice === 'rotate_ok') : ?>
                <div class="notice notice-success"><p>Site token rotated and stored.</p></div>
            <?php elseif ($notice === 'rotate_failed') : ?>
                <div class="notice notice-error"><p>Site token rotation failed.</p></div>
            <?php elseif ($notice === 'profile_ok') : ?>
                <div class="notice notice-success"><p>Site profile updated.</p></div>
            <?php elseif ($notice === 'profile_failed') : ?>
                <div class="notice notice-error"><p>Site profile update failed.</p></div>
            <?php elseif ($notice === 'dispatch_ok') : ?>
                <div class="notice notice-success"><p>Action dispatch requested.</p></div>
            <?php elseif ($notice === 'dispatch_failed') : ?>
                <div class="notice notice-error"><p>Action dispatch failed.</p></div>
            <?php endif; ?>
            <?php if ((bool) get_option('seoauto_allow_insecure_ssl', false)) : ?>
                <div class="notice notice-warning"><p>Insecure SSL mode is enabled for Laravel API calls. Use for local development only.</p></div>
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
                    <tr>
                        <th scope="row">Allow Insecure SSL (Dev)</th>
                        <td>
                            <label>
                                <input type="checkbox" name="seoauto_allow_insecure_ssl" value="1" <?php checked((bool) get_option('seoauto_allow_insecure_ssl', false)); ?>>
                                Disable TLS cert verification for Laravel API calls
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save Settings'); ?>
            </form>

            <hr>
            <h2>Connection</h2>
            <?php
            $oauthStatus = (string) get_option('seoauto_oauth_status', 'pending');
            $oauthProvider = (string) get_option('seoauto_oauth_provider', '');
            $oauthScopes = get_option('seoauto_oauth_scopes', []);
            if (!is_array($oauthScopes)) {
                $oauthScopes = [];
            }
            $oauthConnectedAt = (int) get_option('seoauto_oauth_connected_at', 0);
            $oauthError = (string) get_option('seoauto_oauth_last_error', '');
            $ownerPlatformUserId = (string) get_option('seoauto_owner_platform_user_id', (string) get_current_user_id());
            $siteDescription = (string) get_option('seoauto_site_profile_description', '');
            $siteTaste = (string) get_option('seoauto_site_profile_taste', '');
            ?>

            <table class="widefat striped" style="max-width:900px;margin-bottom:16px;">
                <tbody>
                    <tr><th scope="row">OAuth Status</th><td><?php echo esc_html($oauthStatus); ?></td></tr>
                    <tr><th scope="row">Connected Provider</th><td><?php echo esc_html($oauthProvider !== '' ? $oauthProvider : 'Not connected'); ?></td></tr>
                    <tr><th scope="row">Connected Scopes</th><td><?php echo esc_html(!empty($oauthScopes) ? implode(', ', array_map('strval', $oauthScopes)) : 'None'); ?></td></tr>
                    <tr><th scope="row">Connected At</th><td><?php echo esc_html($oauthConnectedAt > 0 ? wp_date('Y-m-d H:i:s', $oauthConnectedAt) : 'Never'); ?></td></tr>
                    <tr><th scope="row">Last OAuth Error</th><td><?php echo esc_html($oauthError !== '' ? $oauthError : 'None'); ?></td></tr>
                </tbody>
            </table>

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

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-left:12px;">
                <?php wp_nonce_field('seoauto_start_oauth'); ?>
                <input type="hidden" name="action" value="seoauto_start_oauth">
                <button type="submit" class="button button-secondary">Connect Google (via Laravel OAuth)</button>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-left:12px;">
                <?php wp_nonce_field('seoauto_rotate_token'); ?>
                <input type="hidden" name="action" value="seoauto_rotate_token">
                <button type="submit" class="button">Rotate Site Token</button>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-left:12px;">
                <?php wp_nonce_field('seoauto_revoke_oauth'); ?>
                <input type="hidden" name="action" value="seoauto_revoke_oauth">
                <input type="text" name="revocation_reason" placeholder="Revocation reason" style="margin-right:6px;">
                <button type="submit" class="button">Revoke Google OAuth</button>
            </form>

            <h3 style="margin-top:20px;">Update Site Profile (Laravel)</h3>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:900px;">
                <?php wp_nonce_field('seoauto_update_site_profile'); ?>
                <input type="hidden" name="action" value="seoauto_update_site_profile">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="platform_user_id">Owner Platform User ID</label></th>
                        <td><input id="platform_user_id" name="platform_user_id" type="text" class="regular-text" value="<?php echo esc_attr($ownerPlatformUserId); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="site_description">Description</label></th>
                        <td><textarea id="site_description" name="site_description" rows="3" class="large-text"><?php echo esc_textarea($siteDescription); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="site_taste">Taste</label></th>
                        <td><textarea id="site_taste" name="site_taste" rows="3" class="large-text"><?php echo esc_textarea($siteTaste); ?></textarea></td>
                    </tr>
                </table>
                <button type="submit" class="button button-secondary">Update Profile</button>
            </form>

            <h3 style="margin-top:20px;">Manual Action Dispatch</h3>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('seoauto_dispatch_action'); ?>
                <input type="hidden" name="action" value="seoauto_dispatch_action">
                <input type="number" min="1" name="dispatch_action_id" placeholder="Action ID" style="width:140px;margin-right:6px;">
                <button type="submit" class="button">Dispatch Action</button>
            </form>
        </div>
        <?php
    }

    private function updateLocalBriefLinkState(int $briefId, int $postId, string $postUrl, string $articleStatus): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'seoauto_content_briefs';

        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $table,
            [
                'linked_wp_post_id' => $postId,
                'linked_wp_post_url' => $postUrl,
                'article_status' => $articleStatus,
                'assignment_status' => 'completed',
                'updated_at' => current_time('mysql'),
            ],
            ['laravel_content_brief_id' => $briefId],
            ['%d', '%s', '%s', '%s', '%s'],
            ['%d']
        );
    }

    /**
     * Normalize/stabilize Laravel API base URL from settings input.
     *
     * @param mixed $value
     */
    public function sanitizeBaseUrl($value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }

        if (!preg_match('#^https?://#i', $raw)) {
            $raw = 'https://' . $raw;
        }

        $sanitized = esc_url_raw($raw);
        if ($sanitized === '') {
            add_settings_error('seoauto_base_url', 'seoauto_base_url_invalid', 'Invalid Laravel Base URL.');
            return (string) get_option('seoauto_base_url', '');
        }

        $host = wp_parse_url($sanitized, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            add_settings_error('seoauto_base_url', 'seoauto_base_url_invalid_host', 'Laravel Base URL must include a valid host.');
            return (string) get_option('seoauto_base_url', '');
        }

        return rtrim($sanitized, '/');
    }

    private function isBaseUrlSyntaxValid(string $url): bool
    {
        $scheme = wp_parse_url($url, PHP_URL_SCHEME);
        $host = wp_parse_url($url, PHP_URL_HOST);

        if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true)) {
            return false;
        }

        return is_string($host) && $host !== '';
    }
}
