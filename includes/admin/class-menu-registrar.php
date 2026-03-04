<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\Admin;

use SEOAutomation\Connector\Actions\ActionExecutor;
use SEOAutomation\Connector\Actions\ActionRepository;
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

    private ActionRepository $actionRepository;

    private ActionExecutor $actionExecutor;

    public function __construct(
        LaravelClient $client,
        BriefSyncer $briefSyncer,
        SiteRegistrar $siteRegistrar,
        HealthChecker $healthChecker,
        OAuthHandler $oauthHandler,
        SiteTokenManager $tokenManager,
        ActionRepository $actionRepository,
        ActionExecutor $actionExecutor,
        Logger $logger
    )
    {
        $this->client = $client;
        $this->briefSyncer = $briefSyncer;
        $this->siteRegistrar = $siteRegistrar;
        $this->healthChecker = $healthChecker;
        $this->oauthHandler = $oauthHandler;
        $this->tokenManager = $tokenManager;
        $this->actionRepository = $actionRepository;
        $this->actionExecutor = $actionExecutor;
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
        add_action('admin_post_seoauto_delete_local_errors', [$this, 'handleDeleteLocalErrors']);
        add_action('admin_post_seoauto_apply_action', [$this, 'handleApplyAction']);
        add_action('admin_post_seoauto_revert_action', [$this, 'handleRevertAction']);
        add_action('admin_post_seoauto_edit_action_payload', [$this, 'handleEditActionPayload']);
        add_action('admin_post_seoauto_update_action_item', [$this, 'handleUpdateActionItem']);
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
        $table = $wpdb->prefix . 'seoauto_change_logs';
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

    public function handleDeleteLocalErrors(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('seoauto_delete_local_errors');

        $severity = isset($_POST['severity']) ? sanitize_text_field((string) $_POST['severity']) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $allowed = ['all', 'error', 'warning'];
        if (!in_array($severity, $allowed, true)) {
            $severity = 'all';
        }

        global $wpdb;
        $table = $wpdb->prefix . 'seoauto_activity_logs';

        if ($severity === 'error') {
            $deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $wpdb->prepare("DELETE FROM {$table} WHERE severity = %s", 'error')
            );
        } elseif ($severity === 'warning') {
            $deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $wpdb->prepare("DELETE FROM {$table} WHERE severity = %s", 'warning')
            );
        } else {
            $deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                "DELETE FROM {$table} WHERE severity IN ('warning','error')"
            );
        }

        $notice = $deleted === false ? 'local_errors_delete_failed' : 'local_errors_delete_ok';
        $deletedCount = $deleted === false ? 0 : (int) $deleted;

        wp_safe_redirect(add_query_arg([
            'page' => 'seoauto-local-errors',
            'seoauto_notice' => $notice,
            'deleted_count' => $deletedCount,
            'severity' => $severity,
        ], admin_url('admin.php')));
        exit;
    }

    public function handleApplyAction(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('seoauto_apply_action');
        $actionId = isset($_POST['action_id']) ? (int) $_POST['action_id'] : 0;

        if ($actionId > 0) {
            \SEOAutomation\Connector\Queue\QueueManager::enqueueActionExecution($actionId, 50);
            $this->actionRepository->markQueued($actionId);
        }

        wp_safe_redirect(add_query_arg([
            'page' => 'seoauto-logs',
            'seoauto_notice' => 'action_apply_requested',
        ], admin_url('admin.php')));
        exit;
    }

    public function handleRevertAction(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('seoauto_revert_action');
        $actionId = isset($_POST['action_id']) ? (int) $_POST['action_id'] : 0;

        $notice = 'action_revert_failed';
        if ($actionId > 0) {
            $result = $this->actionExecutor->revertByLaravelId($actionId);
            if (($result['status'] ?? '') === 'rolled_back') {
                $notice = 'action_revert_ok';
            }
        }

        wp_safe_redirect(add_query_arg([
            'page' => 'seoauto-logs',
            'seoauto_notice' => $notice,
        ], admin_url('admin.php')));
        exit;
    }

    public function handleEditActionPayload(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('seoauto_edit_action_payload');
        $actionId = isset($_POST['action_id']) ? (int) $_POST['action_id'] : 0;
        $payloadJson = isset($_POST['payload_json']) ? (string) wp_unslash($_POST['payload_json']) : '';
        $payload = json_decode($payloadJson, true);

        $notice = 'action_edit_failed';
        if ($actionId > 0 && is_array($payload)) {
            $updated = $this->actionRepository->updatePayload($actionId, $payload, get_current_user_id());
            if ($updated) {
                $notice = 'action_edit_ok';
            }
        }

        wp_safe_redirect(add_query_arg([
            'page' => 'seoauto-logs',
            'seoauto_notice' => $notice,
        ], admin_url('admin.php')));
        exit;
    }

    public function handleUpdateActionItem(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('seoauto_update_action_item');
        global $wpdb;
        $table = $wpdb->prefix . 'seoauto_admin_action_items';
        $itemId = isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0;
        $status = isset($_POST['status']) ? sanitize_text_field((string) $_POST['status']) : 'open';
        $valid = ['open', 'in_progress', 'resolved'];

        if ($itemId > 0 && in_array($status, $valid, true)) {
            $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $table,
                [
                    'status' => $status,
                    'resolved_at' => $status === 'resolved' ? current_time('mysql') : null,
                    'updated_at' => current_time('mysql'),
                ],
                ['id' => $itemId],
                ['%s', '%s', '%s'],
                ['%d']
            );
        }

        wp_safe_redirect(add_query_arg([
            'page' => 'seoauto-action-items',
            'seoauto_notice' => 'action_item_updated',
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
        add_submenu_page('seoauto', 'Change Center', 'Change Center', 'manage_options', 'seoauto-logs', [$this, 'renderLogsPage']);
        add_submenu_page('seoauto', 'Action Items', 'Action Items', 'manage_options', 'seoauto-action-items', [$this, 'renderActionItemsPage']);
        add_submenu_page('seoauto', 'Activity Logs', 'Activity Logs', 'manage_options', 'seoauto-local-errors', [$this, 'renderLocalErrorsPage']);
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

        register_setting('seoauto_settings', 'seoauto_change_application_mode', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitizeChangeApplicationMode'],
            'default' => 'dangerous_auto_apply',
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
        $status = isset($_GET['status']) ? sanitize_text_field((string) $_GET['status']) : '';
        $filters = [];
        if ($status !== '') {
            $filters['status'] = $status;
        }

        $actions = $this->actionRepository->listActions($filters, 200);
        $changeLogs = $this->actionRepository->listChangeLogs(0, 200, [
            'exclude_event_type' => 'human_action_created',
        ]);
        $humanActionLogs = $this->actionRepository->listChangeLogs(0, 100, [
            'event_type' => 'human_action_created',
        ]);

        global $wpdb;
        $siteId = (int) get_option('seoauto_site_id', 0);
        $itemsTable = $wpdb->prefix . 'seoauto_admin_action_items';
        $itemQuery = $siteId > 0
            ? $wpdb->prepare(
                "SELECT * FROM {$itemsTable} WHERE site_id = %d ORDER BY updated_at DESC LIMIT 200",
                $siteId
            )
            : "SELECT * FROM {$itemsTable} WHERE 1=0";
        $humanItems = $wpdb->get_results($itemQuery, ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        if (!is_array($humanItems)) {
            $humanItems = [];
        }

        $humanItemsByLaravelId = [];
        foreach ($humanItems as $item) {
            $itemLaravelId = (int) ($item['laravel_action_id'] ?? 0);
            if ($itemLaravelId <= 0) {
                continue;
            }

            if (!isset($humanItemsByLaravelId[$itemLaravelId])) {
                $humanItemsByLaravelId[$itemLaravelId] = [];
            }

            $humanItemsByLaravelId[$itemLaravelId][] = $item;
        }

        $actionTitlesByLaravelId = [];
        foreach ($actions as $actionRow) {
            $laravelId = (int) ($actionRow['laravel_action_id'] ?? 0);
            if ($laravelId <= 0) {
                continue;
            }

            $payload = json_decode((string) ($actionRow['action_payload'] ?? '{}'), true);
            if (!is_array($payload)) {
                $payload = [];
            }

            $title = trim((string) ($payload['title'] ?? ''));
            if ($title === '') {
                $title = trim((string) ($actionRow['action_type'] ?? 'Action'));
            }

            $actionTitlesByLaravelId[$laravelId] = $title;
        }

        foreach ($humanItemsByLaravelId as $laravelId => $items) {
            if (!isset($actionTitlesByLaravelId[$laravelId]) && !empty($items[0]['title'])) {
                $actionTitlesByLaravelId[$laravelId] = (string) $items[0]['title'];
            }
        }

        $groupedChangeLogs = [];
        foreach ($changeLogs as $log) {
            $laravelId = (int) ($log['laravel_action_id'] ?? 0);
            if ($laravelId <= 0) {
                continue;
            }

            if (!isset($groupedChangeLogs[$laravelId])) {
                $groupedChangeLogs[$laravelId] = [
                    'laravel_action_id' => $laravelId,
                    'title' => $actionTitlesByLaravelId[$laravelId] ?? 'Action',
                    'last_status' => (string) ($log['status'] ?? ''),
                    'last_at' => (string) ($log['created_at'] ?? ''),
                    'events' => [],
                ];
            }

            $groupedChangeLogs[$laravelId]['events'][] = $log;
        }

        $openHumanItems = 0;
        foreach ($humanItems as $item) {
            if (($item['status'] ?? '') !== 'resolved') {
                $openHumanItems++;
            }
        }
        ?>
        <div class="wrap">
            <h1>Change Center</h1>
            <?php if ($notice === 'action_apply_requested') : ?>
                <div class="notice notice-success"><p>Action queued for execution.</p></div>
            <?php elseif ($notice === 'action_revert_ok') : ?>
                <div class="notice notice-success"><p>Action reverted.</p></div>
            <?php elseif ($notice === 'action_revert_failed') : ?>
                <div class="notice notice-error"><p>Failed to revert action.</p></div>
            <?php elseif ($notice === 'action_edit_ok') : ?>
                <div class="notice notice-success"><p>Action payload updated.</p></div>
            <?php elseif ($notice === 'action_edit_failed') : ?>
                <div class="notice notice-error"><p>Failed to update action payload.</p></div>
            <?php endif; ?>
            <style>
                .seoauto-kpi-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:12px; margin:10px 0 18px; }
                .seoauto-kpi-card { border:1px solid #dcdcde; border-radius:8px; background:#fff; padding:12px; }
                .seoauto-kpi-label { display:block; color:#50575e; font-size:12px; margin-bottom:6px; }
                .seoauto-kpi-value { font-size:20px; font-weight:600; line-height:1.2; }
                .seoauto-badge { display:inline-block; border-radius:999px; padding:2px 8px; font-size:11px; font-weight:600; text-transform:uppercase; }
                .seoauto-status-received, .seoauto-status-pending { background:#f0f0f1; color:#1d2327; }
                .seoauto-status-queued, .seoauto-status-running, .seoauto-status-in-progress { background:#cff4fc; color:#055160; }
                .seoauto-status-applied, .seoauto-status-resolved, .seoauto-status-provider-applied { background:#d1e7dd; color:#0f5132; }
                .seoauto-status-failed, .seoauto-status-rejected, .seoauto-status-provider-error { background:#f8d7da; color:#842029; }
                .seoauto-status-rolled_back { background:#fff3cd; color:#664d03; }
                .seoauto-mono { font-family:Menlo,Consolas,Monaco,monospace; font-size:12px; }
                .seoauto-json pre { max-height:220px; overflow:auto; background:#f6f7f7; border:1px solid #dcdcde; padding:8px; border-radius:4px; }
            </style>

            <div class="seoauto-kpi-grid">
                <div class="seoauto-kpi-card">
                    <span class="seoauto-kpi-label">Automated Actions</span>
                    <span class="seoauto-kpi-value"><?php echo esc_html((string) count($actions)); ?></span>
                </div>
                <div class="seoauto-kpi-card">
                    <span class="seoauto-kpi-label">Execution Events</span>
                    <span class="seoauto-kpi-value"><?php echo esc_html((string) count($changeLogs)); ?></span>
                </div>
                <div class="seoauto-kpi-card">
                    <span class="seoauto-kpi-label">Human Action Items</span>
                    <span class="seoauto-kpi-value"><?php echo esc_html((string) count($humanItems)); ?></span>
                </div>
                <div class="seoauto-kpi-card">
                    <span class="seoauto-kpi-label">Open Human Items</span>
                    <span class="seoauto-kpi-value"><?php echo esc_html((string) $openHumanItems); ?></span>
                </div>
            </div>

            <p style="margin-bottom:12px;">
                Change Center shows machine-applied changes and their execution timeline. Human-only tasks are listed separately below.
                <a href="<?php echo esc_url(admin_url('admin.php?page=seoauto-action-items')); ?>">Open Admin Action Items</a>
            </p>
            <form method="get" style="margin-bottom:12px;">
                <input type="hidden" name="page" value="seoauto-logs">
                <select name="status">
                    <option value="">All statuses</option>
                    <option value="received" <?php selected($status, 'received'); ?>>Received</option>
                    <option value="queued" <?php selected($status, 'queued'); ?>>Queued</option>
                    <option value="running" <?php selected($status, 'running'); ?>>Running</option>
                    <option value="applied" <?php selected($status, 'applied'); ?>>Applied</option>
                    <option value="failed" <?php selected($status, 'failed'); ?>>Failed</option>
                    <option value="rolled_back" <?php selected($status, 'rolled_back'); ?>>Rolled Back</option>
                </select>
                <button class="button" type="submit">Filter</button>
            </form>

            <table class="wp-list-table widefat striped">
                <thead>
                    <tr><th>Laravel ID</th><th>Type</th><th>Status</th><th>Auto</th><th>Received</th><th>Details</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php if (!empty($actions)) : ?>
                    <?php foreach ($actions as $row) : ?>
                        <?php
                        $laravelId = (int) ($row['laravel_action_id'] ?? 0);
                        $actionPayload = json_decode((string) ($row['action_payload'] ?? '{}'), true);
                        $beforeSnapshot = json_decode((string) ($row['before_snapshot'] ?? '{}'), true);
                        $afterSnapshot = json_decode((string) ($row['after_snapshot'] ?? '{}'), true);
                        if (!is_array($actionPayload)) {
                            $actionPayload = [];
                        }
                        if (!is_array($beforeSnapshot)) {
                            $beforeSnapshot = [];
                        }
                        if (!is_array($afterSnapshot)) {
                            $afterSnapshot = [];
                        }
                        ?>
                        <tr>
                            <td><?php echo esc_html((string) $laravelId); ?></td>
                            <td><code><?php echo esc_html((string) ($row['action_type'] ?? '')); ?></code></td>
                            <td><?php echo wp_kses_post($this->renderStatusBadge((string) ($row['status'] ?? 'received'))); ?></td>
                            <td><?php echo !empty($row['auto_apply']) ? 'Yes' : 'No'; ?></td>
                            <td><?php echo esc_html((string) ($row['received_at'] ?? '')); ?></td>
                            <td class="seoauto-json">
                                <details>
                                    <summary>See change</summary>
                                    <strong>Payload</strong>
                                    <pre><?php echo esc_html(wp_json_encode($actionPayload, JSON_PRETTY_PRINT)); ?></pre>
                                    <strong>Before</strong>
                                    <pre><?php echo esc_html($beforeSnapshot !== [] ? wp_json_encode($beforeSnapshot, JSON_PRETTY_PRINT) : '{}'); ?></pre>
                                    <strong>After</strong>
                                    <pre><?php echo esc_html($afterSnapshot !== [] ? wp_json_encode($afterSnapshot, JSON_PRETTY_PRINT) : '{}'); ?></pre>
                                </details>
                            </td>
                            <td>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:6px;">
                                    <?php wp_nonce_field('seoauto_apply_action'); ?>
                                    <input type="hidden" name="action" value="seoauto_apply_action">
                                    <input type="hidden" name="action_id" value="<?php echo esc_attr((string) $laravelId); ?>">
                                    <button class="button button-small" type="submit">Apply</button>
                                </form>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:6px;">
                                    <?php wp_nonce_field('seoauto_revert_action'); ?>
                                    <input type="hidden" name="action" value="seoauto_revert_action">
                                    <input type="hidden" name="action_id" value="<?php echo esc_attr((string) $laravelId); ?>">
                                    <button class="button button-small" type="submit">Revert</button>
                                </form>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <?php wp_nonce_field('seoauto_edit_action_payload'); ?>
                                    <input type="hidden" name="action" value="seoauto_edit_action_payload">
                                    <input type="hidden" name="action_id" value="<?php echo esc_attr((string) $laravelId); ?>">
                                    <textarea name="payload_json" rows="4" style="width:100%;"><?php echo esc_textarea(wp_json_encode($actionPayload, JSON_PRETTY_PRINT)); ?></textarea>
                                    <button class="button button-small" type="submit">Edit Values</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr><td colspan="7">No automated actions found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>

            <h2 style="margin-top:20px;">Execution Timeline</h2>
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr><th>Laravel ID</th><th>Title</th><th>Latest Status</th><th>Last Event At</th><th>Progression</th></tr>
                </thead>
                <tbody>
                    <?php if (!empty($groupedChangeLogs)) : ?>
                        <?php foreach ($groupedChangeLogs as $group) : ?>
                            <?php $events = array_reverse($group['events']); ?>
                            <tr>
                                <td class="seoauto-mono"><?php echo esc_html((string) ($group['laravel_action_id'] ?? 0)); ?></td>
                                <td><?php echo esc_html((string) ($group['title'] ?? 'Action')); ?></td>
                                <td><?php echo wp_kses_post($this->renderStatusBadge((string) ($group['last_status'] ?? 'received'))); ?></td>
                                <td><?php echo esc_html((string) ($group['last_at'] ?? '')); ?></td>
                                <td style="min-width:420px;">
                                    <details>
                                        <summary>Show progression (<?php echo esc_html((string) count($events)); ?> events)</summary>
                                        <table class="widefat striped" style="margin-top:8px;">
                                            <thead>
                                                <tr><th>Time</th><th>Event</th><th>Status</th><th>Note</th><th>Change Data</th></tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($events as $event) : ?>
                                                    <?php
                                                    $before = json_decode((string) ($event['before_snapshot'] ?? '{}'), true);
                                                    $after = json_decode((string) ($event['after_snapshot'] ?? '{}'), true);
                                                    if (!is_array($before)) {
                                                        $before = [];
                                                    }
                                                    if (!is_array($after)) {
                                                        $after = [];
                                                    }
                                                    ?>
                                                    <tr>
                                                        <td><?php echo esc_html((string) ($event['created_at'] ?? '')); ?></td>
                                                        <td><code><?php echo esc_html((string) ($event['event_type'] ?? '')); ?></code></td>
                                                        <td><?php echo wp_kses_post($this->renderStatusBadge((string) ($event['status'] ?? 'received'))); ?></td>
                                                        <td><?php echo esc_html((string) ($event['note'] ?? '')); ?></td>
                                                        <td class="seoauto-json">
                                                            <details>
                                                                <summary>Before/After</summary>
                                                                <strong>Before</strong>
                                                                <pre><?php echo esc_html($before !== [] ? wp_json_encode($before, JSON_PRETTY_PRINT) : '{}'); ?></pre>
                                                                <strong>After</strong>
                                                                <pre><?php echo esc_html($after !== [] ? wp_json_encode($after, JSON_PRETTY_PRINT) : '{}'); ?></pre>
                                                            </details>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </details>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="5">No execution timeline entries yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <h2 style="margin-top:20px;">Human Action Activity</h2>
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr><th>Time</th><th>Laravel ID</th><th>Title</th><th>Category</th><th>Status</th><th>Details</th></tr>
                </thead>
                <tbody>
                    <?php if (!empty($humanActionLogs)) : ?>
                        <?php foreach ($humanActionLogs as $log) : ?>
                            <?php
                            $laravelId = (int) ($log['laravel_action_id'] ?? 0);
                            $linkedItems = $humanItemsByLaravelId[$laravelId] ?? [];
                            $primaryItem = !empty($linkedItems) ? $linkedItems[0] : null;
                            ?>
                            <tr>
                                <td><?php echo esc_html((string) ($log['created_at'] ?? '')); ?></td>
                                <td class="seoauto-mono"><?php echo esc_html((string) $laravelId); ?></td>
                                <td><?php echo esc_html((string) ($primaryItem['title'] ?? 'Manual action required')); ?></td>
                                <td><?php echo esc_html((string) ($primaryItem['category'] ?? 'general')); ?></td>
                                <td><?php echo wp_kses_post($this->renderStatusBadge((string) ($primaryItem['status'] ?? 'open'))); ?></td>
                                <td>
                                    <details>
                                        <summary>Open details</summary>
                                        <p style="margin-top:8px;"><?php echo esc_html((string) ($primaryItem['details'] ?? (string) ($log['note'] ?? ''))); ?></p>
                                        <?php if (!empty($primaryItem['recommended_value'])) : ?>
                                            <p><strong>Recommended Value:</strong> <code><?php echo esc_html((string) $primaryItem['recommended_value']); ?></code></p>
                                        <?php endif; ?>
                                    </details>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="6">No human action activity yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function renderStatusBadge(string $status): string
    {
        $normalized = sanitize_html_class(str_replace('_', '-', strtolower(trim($status))));
        $label = ucwords(str_replace(['-', '_'], ' ', $status));

        return sprintf(
            '<span class="seoauto-badge seoauto-status-%s">%s</span>',
            esc_attr($normalized),
            esc_html($label)
        );
    }

    private function renderLocalLogsFallback(string $remoteError = '', string $notice = '', int $deletedCount = 0): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'seoauto_activity_logs';

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
            <h1>Activity Logs</h1>
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
        <p style="margin-bottom:12px;">
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=seoauto-local-errors')); ?>">Open Local Errors</a>
        </p>
        <?php
    }

    public function renderLocalErrorsPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'seoauto_activity_logs';

        $notice = isset($_GET['seoauto_notice']) ? sanitize_text_field((string) $_GET['seoauto_notice']) : '';
        $deletedCount = isset($_GET['deleted_count']) ? max(0, (int) $_GET['deleted_count']) : 0;
        $severity = isset($_GET['severity']) ? sanitize_text_field((string) $_GET['severity']) : 'error';
        $source = isset($_GET['source']) ? sanitize_text_field((string) $_GET['source']) : '';
        $dateFrom = isset($_GET['date_from']) ? sanitize_text_field((string) $_GET['date_from']) : '';
        $dateTo = isset($_GET['date_to']) ? sanitize_text_field((string) $_GET['date_to']) : '';
        $page = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $perPage = 50;
        $offset = ($page - 1) * $perPage;

        if (!in_array($severity, ['all', 'error', 'warning'], true)) {
            $severity = 'error';
        }

        $where = ['1=1'];
        $params = [];

        if ($severity === 'error') {
            $where[] = 'severity = %s';
            $params[] = 'error';
        } elseif ($severity === 'warning') {
            $where[] = 'severity = %s';
            $params[] = 'warning';
        } else {
            $where[] = "severity IN ('warning','error')";
        }

        $validSources = ['inbound', 'outbound', 'executor', 'admin'];
        if ($source !== '' && in_array($source, $validSources, true)) {
            $where[] = 'source = %s';
            $params[] = $source;
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
            <h1>Local Errors</h1>
            <?php $this->renderLocalErrorsToolbar($notice, $deletedCount, $severity); ?>

            <form method="get">
                <input type="hidden" name="page" value="seoauto-local-errors">
                <select name="severity">
                    <option value="all" <?php selected($severity, 'all'); ?>>Warning + Error</option>
                    <option value="error" <?php selected($severity, 'error'); ?>>Error</option>
                    <option value="warning" <?php selected($severity, 'warning'); ?>>Warning</option>
                </select>
                <select name="source">
                    <option value="">All Sources</option>
                    <option value="inbound" <?php selected($source, 'inbound'); ?>>Inbound</option>
                    <option value="outbound" <?php selected($source, 'outbound'); ?>>Outbound</option>
                    <option value="executor" <?php selected($source, 'executor'); ?>>Executor</option>
                    <option value="admin" <?php selected($source, 'admin'); ?>>Admin</option>
                </select>
                <input type="date" name="date_from" value="<?php echo esc_attr($dateFrom); ?>">
                <input type="date" name="date_to" value="<?php echo esc_attr($dateTo); ?>">
                <button class="button" type="submit">Filter</button>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=seoauto-local-errors')); ?>">Reset</a>
            </form>

            <table class="wp-list-table widefat striped" style="margin-top:12px;">
                <thead>
                    <tr><th>Time</th><th>Severity</th><th>Source</th><th>Correlation</th><th>Event</th><th>Entity</th><th>Error</th></tr>
                </thead>
                <tbody>
                    <?php if (!empty($rows)) : ?>
                        <?php foreach ($rows as $row) : ?>
                            <tr>
                                <td><?php echo esc_html(wp_date('Y-m-d H:i:s', strtotime((string) $row->created_at))); ?></td>
                                <td><?php echo esc_html((string) $row->severity); ?></td>
                                <td><?php echo esc_html((string) $row->source); ?></td>
                                <td><code><?php echo esc_html(substr((string) $row->correlation_id, 0, 8)); ?></code></td>
                                <td><?php echo esc_html((string) $row->event_name); ?></td>
                                <td><?php echo esc_html((string) $row->entity_type . ':' . (string) $row->entity_id); ?></td>
                                <td><?php echo esc_html((string) $row->error_message); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="7">No local errors found.</td></tr>
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

    public function renderActionItemsPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'seoauto_admin_action_items';
        $actionsTable = $wpdb->prefix . 'seoauto_actions';
        $siteId = (int) get_option('seoauto_site_id', 0);
        $itemsQuery = $siteId > 0
            ? $wpdb->prepare(
                "SELECT * FROM {$table} WHERE site_id = %d ORDER BY updated_at DESC LIMIT 200",
                $siteId
            )
            : "SELECT * FROM {$table} WHERE 1=0";
        $notice = isset($_GET['seoauto_notice']) ? sanitize_text_field((string) $_GET['seoauto_notice']) : '';

        $items = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $itemsQuery
        );
        if (!is_array($items)) {
            $items = [];
        }

        $actionRowsByLaravelId = [];
        if (!empty($items)) {
            $laravelIds = [];
            foreach ($items as $item) {
                $laravelId = isset($item->laravel_action_id) ? (int) $item->laravel_action_id : 0;
                if ($laravelId > 0) {
                    $laravelIds[] = $laravelId;
                }
            }
            $laravelIds = array_values(array_unique($laravelIds));

            if (!empty($laravelIds)) {
                $placeholders = implode(',', array_fill(0, count($laravelIds), '%d'));
                $query = $wpdb->prepare(
                    "SELECT laravel_action_id, target_type, target_id, target_url FROM {$actionsTable} WHERE laravel_action_id IN ({$placeholders})",
                    ...$laravelIds
                );
                $actionRows = $wpdb->get_results($query, ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                if (is_array($actionRows)) {
                    foreach ($actionRows as $actionRow) {
                        $laravelId = (int) ($actionRow['laravel_action_id'] ?? 0);
                        if ($laravelId > 0) {
                            $actionRowsByLaravelId[$laravelId] = $actionRow;
                        }
                    }
                }
            }
        }
        ?>
        <div class="wrap">
            <h1>Admin Action Items</h1>
            <?php if ($notice === 'action_item_updated') : ?>
                <div class="notice notice-success"><p>Action item updated.</p></div>
            <?php endif; ?>
            <?php if ($siteId <= 0) : ?>
                <div class="notice notice-warning"><p>Site is not registered yet. Action items are hidden until a site ID is available.</p></div>
            <?php endif; ?>
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr><th>ID</th><th>Title</th><th>Category</th><th>Status</th><th>Target</th><th>Details</th><th>Update</th></tr>
                </thead>
                <tbody>
                    <?php if (!empty($items)) : ?>
                        <?php foreach ($items as $item) : ?>
                            <?php
                            $laravelId = isset($item->laravel_action_id) ? (int) $item->laravel_action_id : 0;
                            $targetLabel = '';
                            $actionRow = $actionRowsByLaravelId[$laravelId] ?? [];
                            if (is_array($actionRow) && !empty($actionRow)) {
                                $targetType = (string) ($actionRow['target_type'] ?? '');
                                $targetId = (string) ($actionRow['target_id'] ?? '');
                                $targetUrl = (string) ($actionRow['target_url'] ?? '');

                                if ($targetType === 'post' && ctype_digit($targetId)) {
                                    $postTitle = get_the_title((int) $targetId);
                                    if (is_string($postTitle) && trim($postTitle) !== '') {
                                        $targetLabel = trim($postTitle);
                                    }
                                }

                                if ($targetLabel === '' && $targetUrl !== '') {
                                    $targetLabel = $targetUrl;
                                }

                                if ($targetLabel === '' && $targetId !== '') {
                                    $targetLabel = "{$targetType}:{$targetId}";
                                }
                            }

                            $displayTitle = (string) $item->title;
                            if ($targetLabel !== '' && stripos($displayTitle, $targetLabel) === false) {
                                $displayTitle = "{$targetLabel} - {$displayTitle}";
                            }
                            ?>
                            <tr>
                                <td><?php echo esc_html((string) $item->id); ?></td>
                                <td><?php echo esc_html($displayTitle); ?></td>
                                <td><?php echo esc_html((string) $item->category); ?></td>
                                <td><?php echo esc_html((string) $item->status); ?></td>
                                <td><?php echo esc_html($targetLabel !== '' ? $targetLabel : 'N/A'); ?></td>
                                <td><?php echo esc_html((string) $item->details); ?></td>
                                <td>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <?php wp_nonce_field('seoauto_update_action_item'); ?>
                                        <input type="hidden" name="action" value="seoauto_update_action_item">
                                        <input type="hidden" name="item_id" value="<?php echo esc_attr((string) $item->id); ?>">
                                        <select name="status">
                                            <option value="open" <?php selected((string) $item->status, 'open'); ?>>Open</option>
                                            <option value="in_progress" <?php selected((string) $item->status, 'in_progress'); ?>>In Progress</option>
                                            <option value="resolved" <?php selected((string) $item->status, 'resolved'); ?>>Resolved</option>
                                        </select>
                                        <button class="button button-small" type="submit">Save</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="7">No human action items found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function renderLocalErrorsToolbar(string $notice, int $deletedCount, string $severity): void
    {
        if ($notice === 'local_errors_delete_ok') {
            ?>
            <div class="notice notice-success"><p><?php echo esc_html(sprintf('Deleted %d local error entries.', $deletedCount)); ?></p></div>
            <?php
        } elseif ($notice === 'local_errors_delete_failed') {
            ?>
            <div class="notice notice-error"><p>Failed to delete local error entries.</p></div>
            <?php
        }
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:12px;">
            <?php wp_nonce_field('seoauto_delete_local_errors'); ?>
            <input type="hidden" name="action" value="seoauto_delete_local_errors">
            <input type="hidden" name="severity" value="<?php echo esc_attr($severity); ?>">
            <button type="submit" class="button" onclick="return confirm('Delete filtered local errors?');">Delete Local Errors</button>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=seoauto-logs')); ?>">Open Execution Logs</a>
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
                <thead><tr><th>ID</th><th>Title</th><th>Focus Keyword</th><th>Brief Details</th><th>Article Status</th><th>Assignment</th><th>Linked Post</th><th>Link Article</th></tr></thead>
                <tbody>
                    <?php if (!empty($rows)) : ?>
                        <?php foreach ($rows as $row) : ?>
                            <?php $payload = json_decode((string) $row->payload, true); ?>
                            <tr>
                                <td><?php echo esc_html((string) $row->laravel_content_brief_id); ?></td>
                                <td><?php echo esc_html((string) ($payload['brief_title'] ?? 'Untitled')); ?></td>
                                <td><?php echo esc_html((string) ($payload['focus_keyword'] ?? '')); ?></td>
                                <td><?php echo wp_kses_post($this->buildBriefDetailsHtml(is_array($payload) ? $payload : [])); ?></td>
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
                        <tr><td colspan="8">No briefs synced yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildBriefDetailsHtml(array $payload): string
    {
        $search_intent = trim((string) ($payload['search_intent'] ?? ''));
        $brief_summary = trim((string) ($payload['brief_summary'] ?? ''));
        $writer_notes = trim((string) ($payload['writer_notes'] ?? ''));
        $outline_items = $this->collectInsightItemsByType($payload, 'outline-item', 10);
        $pain_points = $this->collectInsightItemsByType($payload, 'pain-point', 6);
        $content_ideas = $this->collectInsightItemsByType($payload, 'content-idea', 6);

        $threads = [];
        foreach ((array) ($payload['threads'] ?? []) as $thread_row) {
            if (!is_array($thread_row)) {
                continue;
            }

            $title = trim((string) ($thread_row['thread_title'] ?? ''));
            $subreddit = trim((string) ($thread_row['subreddit'] ?? ''));
            if ($title === '') {
                continue;
            }

            $threads[] = $subreddit !== '' ? "{$title} ({$subreddit})" : $title;
            if (count($threads) >= 5) {
                break;
            }
        }

        $html = '<strong>Search Intent:</strong> ' . esc_html($search_intent !== '' ? $search_intent : '—');
        $html .= '<br><strong>Brief Summary:</strong> ' . esc_html($brief_summary !== '' ? $brief_summary : '—');
        $html .= '<br><strong>Writer Notes:</strong> ' . esc_html($writer_notes !== '' ? $writer_notes : '—');
        $html .= '<br><strong>Recommended Outline</strong>' . $this->renderBulletList($outline_items);
        $html .= '<strong>Reader Pain Points</strong>' . $this->renderBulletList($pain_points);
        $html .= '<strong>Content Angles</strong>' . $this->renderBulletList($content_ideas);
        $html .= '<strong>Source Threads</strong>' . $this->renderBulletList($threads);

        return $html;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private function collectInsightItemsByType(array $payload, string $type, int $limit): array
    {
        $items = [];

        foreach ((array) ($payload['insights'] ?? []) as $insight_row) {
            if (!is_array($insight_row)) {
                continue;
            }

            if ((string) ($insight_row['insight_type'] ?? '') !== $type) {
                continue;
            }

            $value = trim((string) ($insight_row['details'] ?? $insight_row['headline'] ?? ''));
            if ($value === '') {
                continue;
            }

            $items[] = $value;
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param list<string> $items
     */
    private function renderBulletList(array $items): string
    {
        $clean = array_values(
            array_filter(
                array_map(static fn ($item): string => trim((string) $item), $items),
                static fn (string $item): bool => $item !== ''
            )
        );

        if ($clean === []) {
            return '<br>—';
        }

        $html = '<ul style="margin:4px 0 10px 18px;list-style:disc;">';
        foreach ($clean as $item) {
            $html .= '<li>' . esc_html($item) . '</li>';
        }
        $html .= '</ul>';

        return $html;
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
        $providerAlerts = get_option('seoauto_provider_connection_alerts', []);
        if (!is_array($providerAlerts)) {
            $providerAlerts = [];
        }

        ?>
        <div class="wrap">
            <h1>SEO Automation Settings</h1>
            <?php settings_errors('seoauto_base_url'); ?>
            <?php if ($notice === 'register_ok') : ?>
                <div class="notice notice-success"><p>Site registration updated successfully.</p></div>
            <?php elseif ($notice === 'register_missing_base_url') : ?>
                <div class="notice notice-warning"><p>Laravel endpoint configuration is missing.</p></div>
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
            <?php foreach ($providerAlerts as $key => $alert) : ?>
                <?php if (!is_array($alert)) {
                    continue;
                } ?>
                <?php
                $providerName = sanitize_text_field((string) ($alert['provider_name'] ?? $key));
                $taskName = sanitize_text_field((string) ($alert['task_name'] ?? 'unknown'));
                $message = sanitize_text_field((string) ($alert['message'] ?? 'Provider access issue detected.'));
                $resolutionHint = sanitize_text_field((string) ($alert['resolution_hint'] ?? 'Reconnect credentials and verify account permissions.'));
                $statusCode = isset($alert['status_code']) ? (int) $alert['status_code'] : 0;
                $occurrences = max((int) ($alert['occurrences'] ?? 1), 1);
                $lastDetectedAt = sanitize_text_field((string) ($alert['last_detected_at'] ?? 'unknown'));
                ?>
                <div class="notice notice-warning">
                    <p>
                        <strong><?php echo esc_html($providerName); ?> provider issue</strong>
                        <?php echo esc_html($message); ?>
                        <?php if ($statusCode > 0) : ?>
                            <?php echo esc_html(' (HTTP ' . $statusCode . ')'); ?>
                        <?php endif; ?>
                    </p>
                    <p>
                        <?php echo esc_html('Task: ' . $taskName . ' | Occurrences: ' . $occurrences . ' | Last detected: ' . $lastDetectedAt); ?>
                    </p>
                    <p><?php echo esc_html($resolutionHint); ?></p>
                </div>
            <?php endforeach; ?>
            <?php if ((bool) get_option('seoauto_allow_insecure_ssl', false)) : ?>
                <div class="notice notice-warning"><p>Insecure SSL mode is enabled for Laravel API calls. Use for local development only.</p></div>
            <?php endif; ?>
            <?php if ((bool) get_option('seoauto_api_blocked', false)) : ?>
                <?php
                $apiError = (string) get_option('seoauto_api_last_error', '');
                $apiErrorAt = (int) get_option('seoauto_api_last_error_at', 0);
                ?>
                <div class="notice notice-error">
                    <p><strong>Laravel API connectivity issue detected.</strong> Outbound calls from WordPress to the Laravel endpoint are failing.</p>
                    <p><?php echo esc_html($apiError !== '' ? $apiError : 'Unknown transport error'); ?></p>
                    <p><?php echo esc_html($apiErrorAt > 0 ? 'Last failure: ' . wp_date('Y-m-d H:i:s', $apiErrorAt) : ''); ?></p>
                    <p>Recommended: verify firewall/WAF rules, DNS, TLS certs, and outbound HTTP availability from this host. No automatic firewall exception is applied.</p>
                </div>
            <?php endif; ?>
            <form method="post" action="options.php">
                <?php settings_fields('seoauto_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="seoauto_base_url">Laravel Base URL</label></th>
                        <td>
                            <input type="url" class="regular-text" id="seoauto_base_url" name="seoauto_base_url" value="<?php echo esc_attr(rtrim((string) SEOAUTO_LARAVEL_BASE_URL, '/')); ?>" readonly>
                            <p class="description">Managed by plugin configuration and not customer-editable.</p>
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
                        <th scope="row">Change Application Mode</th>
                        <td>
                            <?php $mode = (string) get_option('seoauto_change_application_mode', 'dangerous_auto_apply'); ?>
                            <label style="display:block;margin-bottom:6px;">
                                <input type="radio" name="seoauto_change_application_mode" value="dangerous_auto_apply" <?php checked($mode, 'dangerous_auto_apply'); ?>>
                                Dangerously apply all suggestions
                            </label>
                            <label style="display:block;">
                                <input type="radio" name="seoauto_change_application_mode" value="review_before_apply" <?php checked($mode, 'review_before_apply'); ?>>
                                Review every change before application
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
        return rtrim((string) SEOAUTO_LARAVEL_BASE_URL, '/');
    }

    /**
     * @param mixed $value
     */
    public function sanitizeChangeApplicationMode($value): string
    {
        $mode = trim((string) $value);
        $allowed = ['dangerous_auto_apply', 'review_before_apply'];

        return in_array($mode, $allowed, true) ? $mode : 'dangerous_auto_apply';
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
