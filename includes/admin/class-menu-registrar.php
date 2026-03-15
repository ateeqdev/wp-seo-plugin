<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\Admin;

use SEOWorkerAI\Connector\Actions\ActionExecutor;
use SEOWorkerAI\Connector\Actions\ActionRepository;
use SEOWorkerAI\Connector\API\LaravelClient;
use SEOWorkerAI\Connector\Auth\OAuthHandler;
use SEOWorkerAI\Connector\Auth\SiteTokenManager;
use SEOWorkerAI\Connector\Sync\BriefSyncer;
use SEOWorkerAI\Connector\Sync\HealthChecker;
use SEOWorkerAI\Connector\Sync\SiteRegistrar;
use SEOWorkerAI\Connector\Utils\Logger;

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
    ) {
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

    // =========================================================================
    // Hook registration
    // =========================================================================

    public function registerHooks(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_post_seoworkerai_register_site', [$this, 'handleRegisterSite']);
        add_action('admin_post_seoworkerai_health_check', [$this, 'handleHealthCheck']);
        add_action('admin_post_seoworkerai_start_oauth', [$this, 'handleStartOAuth']);
        add_action('admin_post_seoworkerai_revoke_oauth', [$this, 'handleRevokeOAuth']);
        add_action('admin_post_seoworkerai_rotate_token', [$this, 'handleRotateToken']);
        add_action('admin_post_seoworkerai_update_site_profile', [$this, 'handleUpdateSiteProfile']);
        add_action('admin_post_seoworkerai_update_strategy_settings', [$this, 'handleUpdateStrategySettings']);
        add_action('admin_post_seoworkerai_update_task', [$this, 'handleUpdateTask']);
        add_action('admin_post_seoworkerai_schedule_task', [$this, 'handleScheduleTask']);
        add_action('admin_post_seoworkerai_link_brief', [$this, 'handleLinkBrief']);
        add_action('admin_post_seoworkerai_delete_logs', [$this, 'handleDeleteLogs']);
        add_action('admin_post_seoworkerai_delete_local_errors', [$this, 'handleDeleteLocalErrors']);
        add_action('admin_post_seoworkerai_apply_action', [$this, 'handleApplyAction']);
        add_action('admin_post_seoworkerai_revert_action', [$this, 'handleRevertAction']);
        add_action('admin_post_seoworkerai_edit_action_payload', [$this, 'handleEditActionPayload']);
        add_action('admin_post_seoworkerai_update_action_item', [$this, 'handleUpdateActionItem']);
        add_action('admin_post_seoworkerai_dismiss_audit_notice', [$this, 'handleDismissAuditNotice']);
    }

    public function enqueueAssets(string $hookSuffix): void
    {
        if (strpos($hookSuffix, 'seoworkerai') === false) {
            return;
        }
        wp_enqueue_style('seoworkerai-admin', SEOWORKERAI_PLUGIN_URL.'assets/css/admin.css', [], SEOWORKERAI_VERSION);
        wp_enqueue_script('seoworkerai-admin', SEOWORKERAI_PLUGIN_URL.'assets/js/admin.js', [], SEOWORKERAI_VERSION, true);
    }

    // =========================================================================
    // Form handlers — ALL UNCHANGED from original
    // =========================================================================

    public function handleRegisterSite(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('seoworkerai_register_site');
        $baseUrl = trim((string) get_option('seoworkerai_base_url', ''));
        if ($baseUrl === '' || ! $this->isBaseUrlSyntaxValid($baseUrl)) {
            wp_safe_redirect(add_query_arg(['page' => 'seoworkerai-settings', 'seoworkerai_notice' => 'register_missing_base_url'], admin_url('admin.php')));
            exit;
        }
        $result = $this->siteRegistrar->registerOrUpdate(true);
        $ok = ! isset($result['error']) && (! empty($result['site_id']) || ((int) get_option('seoworkerai_site_id', 0) > 0));
        wp_safe_redirect(add_query_arg(['page' => 'seoworkerai-settings', 'seoworkerai_notice' => $ok ? 'register_ok' : 'register_failed'], admin_url('admin.php')));
        exit;
    }

    public function handleHealthCheck(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('seoworkerai_health_check');
        $result = $this->healthChecker->check();
        $ok = ! empty($result['connected']);
        wp_safe_redirect(add_query_arg(['page' => 'seoworkerai-settings', 'seoworkerai_notice' => $ok ? 'health_ok' : 'health_failed'], admin_url('admin.php')));
        exit;
    }

    public function handleStartOAuth(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('seoworkerai_start_oauth');
        if (! $this->isDomainRatingConfirmed()) {
            wp_safe_redirect(add_query_arg(['page' => 'seoworkerai-settings', 'seoworkerai_notice' => 'domain_rating_required'], admin_url('admin.php')));
            exit;
        }
        try {
            $siteId = (int) get_option('seoworkerai_site_id', 0);
            if ($siteId <= 0) {
                $registrationResult = $this->siteRegistrar->registerOrUpdate(true);
                $siteId = (int) get_option('seoworkerai_site_id', 0);
                if (isset($registrationResult['error']) || $siteId <= 0) {
                    throw new \RuntimeException('Site registration is required before starting OAuth.');
                }
            }
            $oauthUrl = $this->oauthHandler->beginGoogleOAuth(['search_console', 'analytics']);
            if ($oauthUrl === '') {
                throw new \RuntimeException('Missing oauth_url');
            }
            wp_redirect($oauthUrl);
            exit;
        } catch (\Throwable $e) {
            update_option('seoworkerai_oauth_status', 'failed', false);
            update_option('seoworkerai_oauth_last_error', $e->getMessage(), false);
            wp_safe_redirect(add_query_arg(['page' => 'seoworkerai-settings', 'seoworkerai_notice' => 'oauth_init_failed'], admin_url('admin.php')));
            exit;
        }
    }

    public function handleRevokeOAuth(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('seoworkerai_revoke_oauth');
        try {
            $reason = isset($_POST['revocation_reason']) ? sanitize_text_field((string) wp_unslash($_POST['revocation_reason'])) : '';
            $this->client->revokeGoogleOAuth($reason !== '' ? ['revocation_reason' => $reason] : []);
            $notice = 'oauth_revoke_ok';
        } catch (\Throwable $e) {
            $notice = (int) $e->getCode() === 404 ? 'oauth_revoke_ok' : 'oauth_revoke_failed';
            if ($notice !== 'oauth_revoke_ok') {
                update_option('seoworkerai_oauth_last_error', $e->getMessage(), false);
            }
        }
        if (in_array($notice, ['oauth_revoke_ok'], true)) {
            update_option('seoworkerai_oauth_status', 'pending', false);
            update_option('seoworkerai_oauth_provider', '', false);
            update_option('seoworkerai_oauth_scopes', [], false);
            update_option('seoworkerai_oauth_connected_at', 0, false);
            update_option('seoworkerai_oauth_last_error', '', false);
        }
        wp_safe_redirect(add_query_arg(['page' => 'seoworkerai-settings', 'seoworkerai_notice' => $notice], admin_url('admin.php')));
        exit;
    }

    public function handleDismissAuditNotice(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('seoworkerai_dismiss_audit_notice');
        $dismissUntil = time() + (3 * DAY_IN_SECONDS);
        update_user_meta(get_current_user_id(), 'seoworkerai_audit_notice_dismissed_until', $dismissUntil);
        $returnPage = isset($_POST['return_page']) ? sanitize_text_field((string) wp_unslash($_POST['return_page'])) : 'seoworkerai-settings';
        $allowedPages = ['seoworkerai', 'seoworkerai-settings', 'seoworkerai-logs', 'seoworkerai-action-items', 'seoworkerai-briefs'];
        if (! in_array($returnPage, $allowedPages, true)) {
            $returnPage = 'seoworkerai-settings';
        }
        wp_safe_redirect(add_query_arg(['page' => $returnPage, 'seoworkerai_notice' => 'audit_notice_dismissed'], admin_url('admin.php')));
        exit;
    }

    public function handleRotateToken(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('seoworkerai_rotate_token');
        $siteId = (int) get_option('seoworkerai_site_id', 0);
        if ($siteId <= 0) {
            wp_safe_redirect(add_query_arg(['page' => 'seoworkerai-settings', 'seoworkerai_notice' => 'rotate_failed'], admin_url('admin.php')));
            exit;
        }
        try {
            $response = $this->client->rotateSiteToken($siteId);
            $newToken = isset($response['api_key']) ? (string) $response['api_key'] : '';
            if ($newToken === '') {
                throw new \RuntimeException('Token rotation response missing api_key.');
            }
            $this->tokenManager->storeToken($newToken);
            update_option('seoworkerai_oauth_last_error', '', false);
            $notice = 'rotate_ok';
        } catch (\Throwable $e) {
            update_option('seoworkerai_oauth_last_error', $e->getMessage(), false);
            $notice = 'rotate_failed';
        }
        wp_safe_redirect(add_query_arg(['page' => 'seoworkerai-settings', 'seoworkerai_notice' => $notice], admin_url('admin.php')));
        exit;
    }

    public function handleUpdateSiteProfile(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('seoworkerai_update_site_profile');
        $description = isset($_POST['site_profile_description']) ? sanitize_textarea_field((string) wp_unslash($_POST['site_profile_description'])) : '';
        $taste = isset($_POST['site_profile_taste']) ? sanitize_textarea_field((string) wp_unslash($_POST['site_profile_taste'])) : '';
        $locations = $this->sanitizePostedLocations($_POST);
        $primaryLocation = $locations[0] ?? [
            'location_type' => 'primary',
            'location_code' => 2840,
            'location_name' => 'United States',
            'priority' => 0,
        ];
        update_option('seoworkerai_site_profile_description', $description, false);
        update_option('seoworkerai_site_profile_taste', $taste, false);
        update_option('seoworkerai_site_locations', $locations, false);
        update_option('seoworkerai_site_location_code', (int) $primaryLocation['location_code'], false);
        update_option('seoworkerai_site_location_name', (string) $primaryLocation['location_name'], false);

        $siteSettings = $this->sanitizePostedSiteSettings($_POST);
        update_option('seoworkerai_site_seo_settings', $siteSettings, false);
        $domainRatingConfirmed = array_key_exists('domain_rating', $siteSettings) && $siteSettings['domain_rating'] !== null;
        update_option('seoworkerai_domain_rating_confirmed', $domainRatingConfirmed, false);
        $this->savePostedAuthorProfiles($_POST);

        $result = $this->siteRegistrar->registerOrUpdate(true);
        $notice = 'profile_ok';
        if (isset($result['error'])) {
            $notice = 'profile_failed';
        } else {
            $siteId = (int) ($result['site_id'] ?? get_option('seoworkerai_site_id', 0));
            if ($siteId > 0) {
                try {
                    $settingsResponse = $this->client->updateSiteSettings($siteId, $this->buildRemoteSiteSettingsPayload($siteSettings));
                    if (isset($settingsResponse['settings']) && is_array($settingsResponse['settings'])) {
                        update_option('seoworkerai_site_seo_settings', $this->siteRegistrar->sanitizeSiteSettingsPayload($settingsResponse['settings']), false);
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('admin_update_site_settings_failed', ['error' => $e->getMessage()], 'admin');
                    $notice = 'profile_failed';
                }
            }
        }
        wp_safe_redirect(add_query_arg(['page' => 'seoworkerai-settings', 'seoworkerai_notice' => $notice], admin_url('admin.php')));
        exit;
    }

    public function handleUpdateStrategySettings(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('seoworkerai_update_strategy_settings');

        $existingSettings = get_option('seoworkerai_site_seo_settings', []);
        if (! is_array($existingSettings)) {
            $existingSettings = [];
        }
        $incomingSettings = $this->sanitizePostedSiteSettings($_POST);
        if (! array_key_exists('site_settings_prefer_low_difficulty', $_POST)) {
            $incomingSettings['prefer_low_difficulty'] = ! empty($existingSettings['prefer_low_difficulty']);
        }
        if (! array_key_exists('site_settings_allow_low_volume', $_POST)) {
            $incomingSettings['allow_low_volume'] = ! empty($existingSettings['allow_low_volume']);
        }
        $siteSettings = array_merge($existingSettings, [
            'template_id' => $incomingSettings['template_id'],
            'domain_rating' => $incomingSettings['domain_rating'],
            'min_search_volume' => $incomingSettings['min_search_volume'],
            'max_search_volume' => $incomingSettings['max_search_volume'],
            'max_keyword_difficulty' => $incomingSettings['max_keyword_difficulty'],
            'preferred_keyword_type' => $incomingSettings['preferred_keyword_type'],
            'prefer_low_difficulty' => $incomingSettings['prefer_low_difficulty'],
            'allow_low_volume' => $incomingSettings['allow_low_volume'],
        ]);
        update_option('seoworkerai_site_seo_settings', $siteSettings, false);
        update_option('seoworkerai_domain_rating_confirmed', $siteSettings['domain_rating'] !== null, false);

        $result = $this->siteRegistrar->registerOrUpdate(true);
        $notice = 'strategy_settings_ok';
        if (isset($result['error'])) {
            $notice = 'strategy_settings_failed';
        } else {
            $siteId = (int) ($result['site_id'] ?? get_option('seoworkerai_site_id', 0));
            if ($siteId > 0) {
                try {
                    $settingsResponse = $this->client->updateSiteSettings($siteId, $this->buildRemoteSiteSettingsPayload($siteSettings));
                    if (isset($settingsResponse['settings']) && is_array($settingsResponse['settings'])) {
                        update_option('seoworkerai_site_seo_settings', $this->siteRegistrar->sanitizeSiteSettingsPayload($settingsResponse['settings']), false);
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('admin_update_strategy_settings_failed', ['error' => $e->getMessage()], 'admin');
                    $notice = 'strategy_settings_failed';
                }
            }
        }
        wp_safe_redirect(add_query_arg(['page' => 'seoworkerai-settings', 'seoworkerai_notice' => $notice], admin_url('admin.php')));
        exit;
    }

    public function handleUpdateTask(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('seoworkerai_update_task');
        $taskId = isset($_POST['task_id']) ? (int) wp_unslash($_POST['task_id']) : 0;
        if ($taskId <= 0) {
            wp_safe_redirect(add_query_arg(['page' => 'seoworkerai-schedules', 'seoworkerai_notice' => 'task_update_failed'], admin_url('admin.php')));
            exit;
        }
        try {
            $isEnabled = ! empty($_POST['is_enabled']);
            $delayMinutes = isset($_POST['delay_minutes']) ? max(0, (int) wp_unslash($_POST['delay_minutes'])) : 0;
            $this->client->updateTaskConfig($taskId, ['is_enabled' => $isEnabled, 'delay_minutes' => $delayMinutes]);
            $notice = 'task_update_ok';
        } catch (\Throwable $e) {
            $this->logger->warning('admin_task_update_failed', ['error' => $e->getMessage()], 'admin');
            $notice = 'task_update_failed';
        }
        wp_safe_redirect(add_query_arg(['page' => 'seoworkerai-schedules', 'seoworkerai_notice' => $notice], admin_url('admin.php')));
        exit;
    }

    public function handleScheduleTask(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('seoworkerai_schedule_task');
        $taskId = isset($_POST['task_id']) ? (int) wp_unslash($_POST['task_id']) : 0;
        if ($taskId <= 0) {
            wp_safe_redirect(add_query_arg(['page' => 'seoworkerai-schedules', 'seoworkerai_notice' => 'task_schedule_failed'], admin_url('admin.php')));
            exit;
        }
        $payload = [];
        $scheduledFor = isset($_POST['scheduled_for']) ? sanitize_text_field((string) wp_unslash($_POST['scheduled_for'])) : '';
        if ($scheduledFor !== '') {
            $ts = strtotime($scheduledFor);
            if ($ts !== false) {
                $payload['scheduled_for'] = gmdate('c', $ts);
            }
        }
        $inputJson = isset($_POST['input_params_json']) ? trim((string) wp_unslash($_POST['input_params_json'])) : '';
        if ($inputJson !== '') {
            $decoded = json_decode($inputJson, true);
            if (is_array($decoded)) {
                $payload['input_params'] = $decoded;
            }
        }
        try {
            $this->client->scheduleTask($taskId, $payload);
            $notice = 'task_schedule_ok';
        } catch (\Throwable $e) {
            $this->logger->warning('admin_task_schedule_failed', ['error' => $e->getMessage()], 'admin');
            $notice = 'task_schedule_failed';
        }
        wp_safe_redirect(add_query_arg(['page' => 'seoworkerai-schedules', 'seoworkerai_notice' => $notice], admin_url('admin.php')));
        exit;
    }

    public function handleLinkBrief(): void
    {
        if (! current_user_can('edit_posts')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('seoworkerai_link_brief');
        $briefId = isset($_POST['brief_id']) ? (int) wp_unslash($_POST['brief_id']) : 0;
        $postId = isset($_POST['wp_post_id']) ? (int) wp_unslash($_POST['wp_post_id']) : 0;
        $articleStatus = isset($_POST['article_status']) ? sanitize_text_field((string) wp_unslash($_POST['article_status'])) : 'drafted';
        if (! in_array($articleStatus, ['drafted', 'published'], true)) {
            $articleStatus = 'drafted';
        }
        $siteId = (int) get_option('seoworkerai_site_id', 0);
        if ($briefId <= 0 || $postId <= 0 || $siteId <= 0) {
            wp_safe_redirect(add_query_arg(['page' => 'seoworkerai-briefs', 'seoworkerai_notice' => 'brief_link_failed'], admin_url('admin.php')));
            exit;
        }
        $post = get_post($postId);
        if (! $post instanceof \WP_Post) {
            wp_safe_redirect(add_query_arg(['page' => 'seoworkerai-briefs', 'seoworkerai_notice' => 'brief_link_failed'], admin_url('admin.php')));
            exit;
        }
        try {
            $payload = [
                'wp_post_id' => $postId,
                'wp_post_url' => get_permalink($postId),
                'wp_post_title' => get_the_title($postId),
                'wp_post_type' => get_post_type($postId),
                'article_status' => $articleStatus,
                'published_at' => get_post_status($postId) === 'publish' ? gmdate('c', (int) get_post_time('U', true, $postId)) : null,
            ];
            $this->client->linkArticleToBrief($siteId, $briefId, $payload);
            $this->updateLocalBriefLinkState($briefId, $postId, (string) get_permalink($postId), (string) get_the_title($postId), (string) get_post_type($postId), $articleStatus);
            $notice = 'brief_link_ok';
        } catch (\Throwable $e) {
            $this->logger->warning('admin_brief_link_failed', ['error' => $e->getMessage()], 'admin');
            $notice = 'brief_link_failed';
        }
        wp_safe_redirect(add_query_arg(['page' => 'seoworkerai-briefs', 'seoworkerai_notice' => $notice], admin_url('admin.php')));
        exit;
    }

    public function handleDeleteLogs(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('seoworkerai_delete_logs');
        global $wpdb;
        $table = $wpdb->prefix.'seoworkerai_changes';
        $deleted = $wpdb->query("DELETE FROM {$table}"); // phpcs:ignore
        $notice = $deleted === false ? 'logs_delete_failed' : 'logs_delete_ok';
        wp_safe_redirect(add_query_arg(['page' => 'seoworkerai-logs', 'seoworkerai_notice' => $notice, 'deleted_count' => $deleted === false ? 0 : (int) $deleted], admin_url('admin.php')));
        exit;
    }

    public function handleDeleteLocalErrors(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('seoworkerai_delete_local_errors');
        $severity = isset($_POST['severity']) ? sanitize_text_field((string) wp_unslash($_POST['severity'])) : 'all';
        if (! in_array($severity, ['all', 'error', 'warning'], true)) {
            $severity = 'all';
        }
        global $wpdb;
        $table = $wpdb->prefix.'seoworkerai_logs';
        if ($severity === 'error') {
            $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE severity = %s", 'error')); // phpcs:ignore
        } elseif ($severity === 'warning') {
            $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE severity = %s", 'warning')); // phpcs:ignore
        } else {
            $deleted = $wpdb->query("DELETE FROM {$table} WHERE severity IN ('warning','error')"); // phpcs:ignore
        }
        $notice = $deleted === false ? 'local_errors_delete_failed' : 'local_errors_delete_ok';
        wp_safe_redirect(add_query_arg(['page' => 'seoworkerai-local-errors', 'seoworkerai_notice' => $notice, 'deleted_count' => $deleted === false ? 0 : (int) $deleted, 'severity' => $severity], admin_url('admin.php')));
        exit;
    }

    public function handleApplyAction(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('seoworkerai_apply_action');
        $actionId = isset($_POST['action_id']) ? (int) wp_unslash($_POST['action_id']) : 0;
        if ($actionId > 0) {
            \SEOWorkerAI\Connector\Queue\QueueManager::enqueueActionExecution($actionId, 50);
            $this->actionRepository->markQueued($actionId);
        }
        $returnPage = $this->resolveActionRedirectPage();
        wp_safe_redirect(add_query_arg(['page' => $returnPage, 'seoworkerai_notice' => 'action_apply_requested'], admin_url('admin.php')));
        exit;
    }

    public function handleRevertAction(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('seoworkerai_revert_action');
        $actionId = isset($_POST['action_id']) ? (int) wp_unslash($_POST['action_id']) : 0;
        $notice = 'action_revert_failed';
        if ($actionId > 0) {
            try {
                $result = $this->actionExecutor->revertByLaravelId($actionId);
                if (($result['status'] ?? '') === 'rolled_back') {
                    $notice = 'action_revert_ok';
                } else {
                    $error = trim((string) ($result['error'] ?? 'Rollback failed.'));
                    $this->actionRepository->logAdminFailure($actionId, 'Rollback failed: '.$error, ['source' => 'manual_revert']);
                    $this->logger->warning('admin_revert_failed', ['entity_type' => 'action', 'entity_id' => (string) $actionId, 'error' => $error], 'admin');
                }
            } catch (\Throwable $e) {
                $this->actionRepository->logAdminFailure($actionId, 'Rollback failed: '.$e->getMessage(), ['source' => 'manual_revert_exception']);
                $this->logger->error('admin_revert_exception', ['entity_type' => 'action', 'entity_id' => (string) $actionId, 'error' => $e->getMessage()], 'admin');
            }
        }
        $returnPage = $this->resolveActionRedirectPage();
        wp_safe_redirect(add_query_arg(['page' => $returnPage, 'seoworkerai_notice' => $notice], admin_url('admin.php')));
        exit;
    }

    public function handleEditActionPayload(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('seoworkerai_edit_action_payload');
        $actionId = isset($_POST['action_id']) ? (int) wp_unslash($_POST['action_id']) : 0;
        $payloadJson = isset($_POST['payload_json']) ? (string) wp_unslash($_POST['payload_json']) : '';
        $payloadFields = isset($_POST['payload_fields']) && is_array($_POST['payload_fields']) ? wp_unslash($_POST['payload_fields']) : [];
        $payload = json_decode($payloadJson, true);
        $notice = 'action_edit_failed';
        if ($actionId > 0) {
            $action = $this->actionRepository->findByLaravelId($actionId);
            $basePayload = [];
            $actionType = is_array($action) ? (string) ($action['action_type'] ?? '') : '';
            if (is_array($action)) {
                $decoded = json_decode((string) ($action['action_payload'] ?? '{}'), true);
                $basePayload = is_array($decoded) ? $decoded : [];
            }
            try {
                if (is_array($payloadFields) && ! empty($payloadFields)) {
                    $payload = $this->applyPayloadFieldEdits($basePayload, $payloadFields);
                }
                if ($payloadJson !== '' && ! is_array($payload)) {
                    throw new \RuntimeException('Payload JSON is invalid.');
                }
                if (! is_array($payload)) {
                    throw new \RuntimeException('No editable payload received.');
                }
                $validationError = $this->validateEditedPayload($actionType, $payload);
                if ($validationError !== null) {
                    $this->actionRepository->logAdminFailure($actionId, 'Edit failed validation: '.$validationError, ['source' => 'payload_validation']);
                    $this->logger->warning('admin_edit_validation_failed', ['entity_type' => 'action', 'entity_id' => (string) $actionId, 'error' => $validationError], 'admin');
                    $notice = 'action_edit_validation_failed';
                } else {
                    $updated = $this->actionRepository->updatePayload($actionId, $payload, get_current_user_id());
                    if ($updated) {
                        $notice = 'action_edit_ok';
                        if (is_array($action) && ($action['status'] ?? '') === 'applied') {
                            \SEOWorkerAI\Connector\Queue\QueueManager::enqueueActionExecution($actionId, 10);
                            $this->actionRepository->markQueued($actionId);
                            $notice = 'action_edit_ok_reapply';
                        }
                    } else {
                        $this->actionRepository->logAdminFailure($actionId, 'Edit failed: payload update returned false.', ['source' => 'payload_update']);
                    }
                }
            } catch (\Throwable $e) {
                $this->actionRepository->logAdminFailure($actionId, 'Edit failed: '.$e->getMessage(), ['source' => 'payload_exception']);
                $this->logger->error('admin_edit_exception', ['entity_type' => 'action', 'entity_id' => (string) $actionId, 'error' => $e->getMessage()], 'admin');
            }
        }
        wp_safe_redirect(add_query_arg(['page' => 'seoworkerai-logs', 'seoworkerai_notice' => $notice], admin_url('admin.php')));
        exit;
    }

    public function handleUpdateActionItem(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('seoworkerai_update_action_item');
        global $wpdb;
        $table = $wpdb->prefix.'seoworkerai_action_items';
        $itemId = isset($_POST['item_id']) ? (int) wp_unslash($_POST['item_id']) : 0;
        $status = isset($_POST['status']) ? sanitize_text_field((string) wp_unslash($_POST['status'])) : 'open';
        $valid = ['open', 'in_progress', 'resolved'];
        if ($itemId > 0 && in_array($status, $valid, true)) {
            $wpdb->update( // phpcs:ignore
                $table,
                ['status' => $status, 'resolved_at' => $status === 'resolved' ? current_time('mysql') : null, 'updated_at' => current_time('mysql')],
                ['id' => $itemId],
                ['%s', '%s', '%s'],
                ['%d']
            );
        }
        wp_safe_redirect(add_query_arg(['page' => 'seoworkerai-action-items', 'seoworkerai_notice' => 'action_item_updated'], admin_url('admin.php')));
        exit;
    }

    // =========================================================================
    // Menu registration — UNCHANGED
    // =========================================================================

    public function registerMenu(): void
    {
        add_menu_page('SEOWorkerAI', 'SEOWorkerAI', 'manage_options', 'seoworkerai', [$this, 'renderSettingsPage'], 'dashicons-chart-line', 80);
        add_submenu_page('seoworkerai', 'Settings', 'Settings', 'manage_options', 'seoworkerai', [$this, 'renderSettingsPage']);
        add_submenu_page('seoworkerai', 'Activity', 'Activity', 'manage_options', 'seoworkerai-activity', [$this, 'renderActivityPage']);
        add_submenu_page('seoworkerai', 'Change Center', 'Change Center', 'manage_options', 'seoworkerai-logs', [$this, 'renderLogsPage']);
        add_submenu_page('seoworkerai', 'Action Items', 'Action Items', 'manage_options', 'seoworkerai-action-items', [$this, 'renderActionItemsPage']);
        add_submenu_page('seoworkerai', 'Content Briefs', 'Content Briefs', 'edit_posts', 'seoworkerai-briefs', [$this, 'renderBriefsPage']);
        add_submenu_page(null, 'Debug Logs', 'Debug Logs', 'manage_options', 'seoworkerai-local-errors', [$this, 'renderLocalErrorsPage']);
        add_submenu_page(null, 'Schedules', 'Schedules', 'manage_options', 'seoworkerai-schedules', [$this, 'renderSchedulesPage']);
        add_submenu_page(null, 'Settings', 'Settings', 'manage_options', 'seoworkerai-settings', [$this, 'renderSettingsPage']);
        add_submenu_page(null, 'OAuth Callback', 'OAuth Callback', 'manage_options', 'seoworkerai-oauth-callback', [$this, 'renderOauthCallbackPage']);
        add_submenu_page(null, 'OAuth Callback', 'OAuth Callback', 'manage_options', 'seoworkerai-oauth-complete', [$this, 'renderOauthCallbackPage']);
    }

    public function registerSettings(): void
    {
        register_setting('seoworkerai_settings', 'seoworkerai_primary_seo_adapter', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'auto']);
        register_setting('seoworkerai_settings', 'seoworkerai_change_application_mode', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitizeChangeApplicationMode'], 'default' => 'dangerous_auto_apply']);
        register_setting('seoworkerai_settings', 'seoworkerai_debug_enabled', ['type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => false]);
        register_setting('seoworkerai_settings', 'seoworkerai_allow_insecure_ssl', ['type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => false]);
        register_setting('seoworkerai_settings', 'seoworkerai_excluded_change_audit_pages', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitizeExcludedChangeAuditPages'], 'default' => '']);
    }

    // =========================================================================
    // REDESIGNED: renderSettingsPage
    // ─ Single-column layout, progressive onboarding stepper.
    // ─ The shared seoworkerai-shell nav is rendered inline here (Settings only).
    //   All other pages still call renderAdminShellHeader() which keeps its
    //   own shell rendering logic, unchanged.
    // =========================================================================

    public function renderSettingsPage(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        // ── Gather all variables (identical to original) ───────────────────
        $notice = isset($_GET['seoworkerai_notice']) ? sanitize_text_field((string) $_GET['seoworkerai_notice']) : '';
        $oauthStatus = (string) get_option('seoworkerai_oauth_status', 'pending');
        $oauthProvider = (string) get_option('seoworkerai_oauth_provider', '');
        $oauthScopes = get_option('seoworkerai_oauth_scopes', []);
        if (! is_array($oauthScopes)) {
            $oauthScopes = [];
        }
        $oauthConnectedAt = (int) get_option('seoworkerai_oauth_connected_at', 0);
        $oauthError = (string) get_option('seoworkerai_oauth_last_error', '');
        $adapter = (string) get_option('seoworkerai_primary_seo_adapter', 'auto');
        $mode = (string) get_option('seoworkerai_change_application_mode', 'dangerous_auto_apply');
        $siteId = (int) get_option('seoworkerai_site_id', 0);
        $lastCron = (int) get_option('seoworkerai_last_cron_run', 0);
        $lastUserSync = (int) get_option('seoworkerai_last_user_sync', 0);
        $lastBriefSync = (int) get_option('seoworkerai_last_brief_sync', 0);
        $siteDescription = (string) get_option('seoworkerai_site_profile_description', '');
        $siteTaste = (string) get_option('seoworkerai_site_profile_taste', '');
        $siteLocations = $this->siteRegistrar->normalizeLocationsOption(get_option('seoworkerai_site_locations', []));
        if ($siteLocations === []) {
            $siteLocations = [[
                'location_type' => 'primary',
                'location_code' => (int) get_option('seoworkerai_site_location_code', 2840),
                'location_name' => (string) get_option('seoworkerai_site_location_name', 'United States'),
                'priority' => 0,
            ]];
        }
        $siteSeoSettings = get_option('seoworkerai_site_seo_settings', []);
        if (! is_array($siteSeoSettings)) {
            $siteSeoSettings = [];
        }
        // FIX: declare $domainRatingConfirmed before any conditional that reads it
        $domainRatingConfirmed = (bool) get_option('seoworkerai_domain_rating_confirmed', false);

        $billing = get_option('seoworkerai_billing', []);
        if (! is_array($billing)) {
            $billing = [];
        }
        $initialAuditStatus = (string) get_option('seoworkerai_initial_audit_status', 'pending');
        $initialAuditMessage = (string) get_option('seoworkerai_initial_audit_message', '');
        $initialAuditStartedAt = (int) get_option('seoworkerai_initial_audit_started_at', 0);
        $initialAuditCompletedAt = (int) get_option('seoworkerai_initial_audit_completed_at', 0);
        $initialAuditLastRunAt = $initialAuditCompletedAt > 0 ? $initialAuditCompletedAt : $initialAuditStartedAt;
        $initialAuditLastRunLabel = $initialAuditLastRunAt > 0 ? wp_date('Y-m-d H:i', $initialAuditLastRunAt) : 'Not run yet';
        $isInitialAuditCompleted = $initialAuditCompletedAt > 0 || in_array($initialAuditStatus, ['completed', 'already_completed'], true);
        $isInitialAuditRunning = $initialAuditStartedAt > 0 || in_array($initialAuditStatus, ['queued', 'in_progress', 'already_started'], true);
        $auditMetrics = $this->getInitialAuditMetrics();

        $siteSettingTemplates = [];
        $availableLocations = $this->getAvailableLocationOptions();

        $domainRatingCheckedAt = ! empty($siteSeoSettings['domain_rating_checked_at'])
            ? strtotime((string) $siteSeoSettings['domain_rating_checked_at'])
            : false;
        $domainRatingInputValue = array_key_exists('domain_rating', $siteSeoSettings) && $siteSeoSettings['domain_rating'] !== null
            ? (string) $siteSeoSettings['domain_rating']
            : '';

        $excludedRaw = (string) get_option('seoworkerai_excluded_change_audit_pages', '');
        $excludedItems = array_values(array_filter(array_map('trim', explode("\n", $excludedRaw))));
        $excludedIds = [];
        foreach ($excludedItems as $item) {
            if (ctype_digit(trim($item))) {
                $excludedIds[] = (int) trim($item);
            }
        }

        $isConnected = $oauthStatus === 'active';

        // Live fetch from API when connected (same as original)
        if ($siteId > 0 && $this->tokenManager->hasToken()) {
            try {
                $settingsResponse = $this->client->getSiteSettings($siteId);
                if (isset($settingsResponse['settings']) && is_array($settingsResponse['settings'])) {
                    $siteSeoSettings = $this->siteRegistrar->sanitizeSiteSettingsPayload($settingsResponse['settings']);
                    update_option('seoworkerai_site_seo_settings', $siteSeoSettings, false);
                }
                if (isset($settingsResponse['billing']) && is_array($settingsResponse['billing'])) {
                    $billing = \SEOWorkerAI\Connector\Sync\SiteRegistrar::sanitizeBillingPayload($settingsResponse['billing']);
                    update_option('seoworkerai_billing', $billing, false);
                }
                if (isset($settingsResponse['initial_site_audit']) && is_array($settingsResponse['initial_site_audit'])) {
                    $initialAuditPayload = \SEOWorkerAI\Connector\Sync\SiteRegistrar::sanitizeInitialAuditPayload($settingsResponse['initial_site_audit']);
                    update_option('seoworkerai_initial_audit_status', $initialAuditPayload['status'], false);
                    update_option('seoworkerai_initial_audit_message', $initialAuditPayload['message'], false);
                    if ($initialAuditPayload['started_at'] > 0) {
                        update_option('seoworkerai_initial_audit_started_at', $initialAuditPayload['started_at'], false);
                        $initialAuditStartedAt = $initialAuditPayload['started_at'];
                    }
                    if ($initialAuditPayload['completed_at'] > 0) {
                        update_option('seoworkerai_initial_audit_completed_at', $initialAuditPayload['completed_at'], false);
                        $initialAuditCompletedAt = $initialAuditPayload['completed_at'];
                    }
                    $initialAuditStatus = $initialAuditPayload['status'];
                    $initialAuditMessage = $initialAuditPayload['message'];
                    $initialAuditLastRunAt = $initialAuditCompletedAt > 0 ? $initialAuditCompletedAt : $initialAuditStartedAt;
                    $initialAuditLastRunLabel = $initialAuditLastRunAt > 0 ? wp_date('Y-m-d H:i', $initialAuditLastRunAt) : 'Not run yet';
                    $isInitialAuditCompleted = $initialAuditCompletedAt > 0 || in_array($initialAuditStatus, ['completed', 'already_completed'], true);
                    $isInitialAuditRunning = $initialAuditStartedAt > 0 || in_array($initialAuditStatus, ['queued', 'in_progress', 'already_started'], true);
                }
                $siteSettingTemplates = isset($settingsResponse['templates']) && is_array($settingsResponse['templates']) ? $settingsResponse['templates'] : [];
                if (isset($settingsResponse['locations']) && is_array($settingsResponse['locations'])) {
                    $siteLocations = $this->siteRegistrar->normalizeLocationsOption($settingsResponse['locations']);
                }
                // Refresh domain rating confirmed after live pull
                $domainRatingConfirmed = array_key_exists('domain_rating', $siteSeoSettings) && $siteSeoSettings['domain_rating'] !== null;
                $domainRatingInputValue = $domainRatingConfirmed ? (string) $siteSeoSettings['domain_rating'] : '';
            } catch (\Throwable $e) {
                $this->logger->warning('admin_fetch_site_settings_failed', ['error' => $e->getMessage()], 'admin');
            }
        }

        $allPosts = get_posts([
            'post_type' => ['post', 'page'],
            'post_status' => 'publish',
            'posts_per_page' => 500,
            'orderby' => 'title',
            'order' => 'ASC',
            'fields' => 'ids',
        ]);

        $authorProfiles = $this->getAuthorProfiles();

        // ── Derive onboarding step states ─────────────────────────────────
        $strategyDone = $domainRatingConfirmed;
        $googleDone = $isConnected;
        $step1State = $strategyDone ? 'is-done' : 'is-active';
        $step2State = $strategyDone ? ($googleDone ? 'is-done' : 'is-active') : 'is-pending';
        $step3State = ($strategyDone && $googleDone) ? 'is-active' : 'is-pending';

        $paymentPending = ! empty($billing['payment_required']);
        $showAuditBanner = $isInitialAuditCompleted && ! $this->isAuditNoticeDismissed() && ! $auditMetrics['is_sync_pending'];

        ?>
<div class="seoworkerai-page">

  <!-- Shell nav -->
  <nav class="seoworkerai-shell">
    <div class="seoworkerai-shell-brand">
      <div class="seoworkerai-shell-brand-mark">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
          <rect x="2" y="2" width="5" height="5" rx="1" fill="white" opacity=".9"/>
          <rect x="9" y="2" width="5" height="5" rx="1" fill="white" opacity=".6"/>
          <rect x="2" y="9" width="5" height="5" rx="1" fill="white" opacity=".6"/>
          <rect x="9" y="9" width="5" height="5" rx="1" fill="white" opacity=".3"/>
        </svg>
      </div>
      <span class="seoworkerai-shell-name">SEOWorkerAI</span>
    </div>
    <div class="seoworkerai-tabs">
      <?php
      $navTabs = [
          'seoworkerai' => ['label' => 'Settings',       'cap' => 'manage_options'],
          'seoworkerai-logs' => ['label' => 'Change Center',  'cap' => 'manage_options'],
          'seoworkerai-action-items' => ['label' => 'Action Items',   'cap' => 'manage_options'],
          'seoworkerai-briefs' => ['label' => 'Content Briefs', 'cap' => 'edit_posts'],
      ];
        $currentPage = isset($_GET['page']) ? sanitize_text_field((string) $_GET['page']) : 'seoworkerai';
        foreach ($navTabs as $slug => $tab) {
            if (! current_user_can((string) $tab['cap'])) {
                continue;
            }
            $active = ($currentPage === $slug || ($slug === 'seoworkerai' && $currentPage === 'seoworkerai-settings')) ? 'is-active' : '';
            ?>
          <a class="seoworkerai-tab <?php echo esc_attr($active); ?>"
             href="<?php echo esc_url(admin_url('admin.php?page='.$slug)); ?>">
            <?php echo esc_html((string) $tab['label']); ?>
          </a>
      <?php } ?>
    </div>
  </nav>

  <?php if ($isInitialAuditCompleted || $isInitialAuditRunning) { ?>
  <!-- ═══ AUDIT BANNER — full-width, between nav and body ═══════════ -->
  <div class="seoworkerai-audit-banner-wrap">
    <div class="seoworkerai-audit-banner">
      <?php if ($isInitialAuditCompleted && ! $auditMetrics['is_sync_pending'] && $auditMetrics['issues_found'] > 0) { ?>
        <div class="seoworkerai-audit-metric">
          <span class="seoworkerai-audit-value"><?php echo esc_html((string) $auditMetrics['issues_found']); ?></span>
          <span class="seoworkerai-audit-label">Issues Found</span>
        </div>
        <div class="seoworkerai-audit-divider"></div>
        <div class="seoworkerai-audit-metric">
          <span class="seoworkerai-audit-value seoworkerai-audit-value-success"><?php echo esc_html((string) $auditMetrics['applied']); ?></span>
          <span class="seoworkerai-audit-label">Fixed Automatically</span>
        </div>
        <div class="seoworkerai-audit-divider"></div>
        <div class="seoworkerai-audit-metric">
          <span class="seoworkerai-audit-value seoworkerai-audit-value-warning"><?php echo esc_html((string) $auditMetrics['needs_human_review']); ?></span>
          <span class="seoworkerai-audit-label">Need Your Review</span>
        </div>
        <div class="seoworkerai-audit-divider"></div>
      <?php } ?>
      <div class="seoworkerai-audit-metric">
        <span class="seoworkerai-audit-label" style="margin-bottom:4px">Status</span>
        <?php if ($isInitialAuditCompleted) { ?>
          <span class="seoworkerai-badge seoworkerai-badge--green">Complete</span>
        <?php } elseif ($auditMetrics['is_sync_pending']) { ?>
          <span class="seoworkerai-badge seoworkerai-badge--blue">Syncing</span>
        <?php } else { ?>
          <span class="seoworkerai-badge seoworkerai-badge--blue">Running</span>
        <?php } ?>
      </div>
      <?php if ($initialAuditLastRunAt > 0) { ?>
        <div class="seoworkerai-audit-metric">
          <span class="seoworkerai-audit-label" style="margin-bottom:4px">Last Run</span>
          <span style="font-size:13px;color:var(--ink)"><?php echo esc_html(wp_date('Y-m-d H:i', $initialAuditLastRunAt)); ?></span>
        </div>
      <?php } ?>
      <div class="seoworkerai-audit-metric">
        <span class="seoworkerai-audit-label" style="margin-bottom:4px">Plan</span>
        <span style="font-size:13px;color:var(--ink)"><?php echo esc_html((string) ($billing['plan_name'] ?? 'SEOWorkerAI Starter')); ?></span>
      </div>
      <div class="seoworkerai-audit-metric">
        <span class="seoworkerai-audit-label" style="margin-bottom:4px">Ongoing Automation</span>
        <?php if ($paymentPending) { ?>
          <?php if (! empty($billing['payment_url'])) { ?>
            <a href="<?php echo esc_url((string) $billing['payment_url']); ?>" target="_blank" rel="noopener noreferrer"
               class="seoworkerai-badge seoworkerai-badge--amber" style="text-decoration:none">Upgrade to continue ↗</a>
          <?php } else { ?>
            <span class="seoworkerai-badge seoworkerai-badge--gray">Upgrade to continue</span>
          <?php } ?>
        <?php } else { ?>
          <span class="seoworkerai-badge seoworkerai-badge--green">Active</span>
        <?php } ?>
      </div>
      <div class="seoworkerai-audit-actions">
        <a class="seoworkerai-btn seoworkerai-btn--secondary seoworkerai-btn--sm"
           href="<?php echo esc_url(admin_url('admin.php?page=seoworkerai-logs')); ?>">View changes →</a>
        <?php if ($paymentPending && ! empty($billing['payment_url']) && $showAuditBanner) { ?>
          <a class="seoworkerai-btn seoworkerai-btn--primary seoworkerai-btn--sm"
             href="<?php echo esc_url((string) $billing['payment_url']); ?>"
             target="_blank" rel="noopener noreferrer">See plans</a>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('seoworkerai_dismiss_audit_notice'); ?>
            <input type="hidden" name="action" value="seoworkerai_dismiss_audit_notice">
            <input type="hidden" name="return_page" value="seoworkerai">
            <button type="submit" class="seoworkerai-btn seoworkerai-btn--secondary seoworkerai-btn--sm">Dismiss</button>
          </form>
        <?php } ?>
      </div>
    </div>
  </div>
  <?php } ?>

  <div class="seoworkerai-body seoworkerai-body--narrow">

    <?php $this->renderNotice($notice); ?>

    <h1 class="seoworkerai-page-title">Settings</h1>
    <p class="seoworkerai-page-desc">Connect your site once. SEOWorkerAI handles the rest.</p>

    <!-- ═══════════════════════════════════════════════════════════════
         ONBOARDING STEPPER
         Step states: is-pending | is-active | is-done
         JS (admin.js initStepper) handles open/close + advance.
    ════════════════════════════════════════════════════════════════ -->
    <div class="seoworkerai-stepper">
      <div class="seoworkerai-stepper-steps">

        <!-- STEP 1: Strategy -->
        <div class="seoworkerai-step seoworkerai-step <?php echo esc_attr($step1State); ?>" data-step="strategy">
          <div class="seoworkerai-step-header seoworkerai-step-header">
            <div class="seoworkerai-step-num">
              <span class="seoworkerai-step-num-digit">1</span>
              <svg class="seoworkerai-step-num-check" width="12" height="12" viewBox="0 0 12 12" fill="none">
                <path d="M2 6l3 3 5-5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </div>
            <div class="seoworkerai-step-info">
              <div class="seoworkerai-step-label">Strategy</div>
              <div class="seoworkerai-step-sublabel">
                <?php if ($strategyDone) {
                    echo esc_html('Domain rating '.($siteSeoSettings['domain_rating'] ?? '–').' · Max KD '.($siteSeoSettings['max_keyword_difficulty'] ?? 100));
                } else {
                    echo 'Set your domain rating and keyword targets before connecting Google';
                } ?>
              </div>
            </div>
            <div class="seoworkerai-step-right">
              <?php if ($strategyDone) { ?>
                <button type="button" class="seoworkerai-step-edit-link seoworkerai-step-edit-link">Edit</button>
              <?php } ?>
              <span class="seoworkerai-step-chevron">
                <svg width="10" height="6" viewBox="0 0 10 6" fill="none">
                  <path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
              </span>
            </div>
          </div>

          <div class="seoworkerai-step-body">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:18px">
              <?php wp_nonce_field('seoworkerai_update_strategy_settings'); ?>
              <input type="hidden" name="action" value="seoworkerai_update_strategy_settings">

              <div class="seoworkerai-grid-3" style="margin-bottom:16px">
                <div class="seoworkerai-field">
                  <label class="seoworkerai-label">Domain rating <span style="color:var(--red)">*</span></label>
                  <input type="number" class="seoworkerai-input" name="site_settings_domain_rating"
                    min="0" max="100" required
                    value="<?php echo esc_attr($domainRatingInputValue); ?>">
                  <span class="seoworkerai-hint">Your Ahrefs DR or equivalent (0–100).</span>
                </div>
                <div class="seoworkerai-field">
                  <label class="seoworkerai-label">Min search volume</label>
                  <input type="number" class="seoworkerai-input" name="site_settings_min_search_volume" min="0"
                    value="<?php echo esc_attr((string) ($siteSeoSettings['min_search_volume'] ?? 0)); ?>">
                </div>
                <div class="seoworkerai-field">
                  <label class="seoworkerai-label">Max keyword difficulty</label>
                  <input type="number" class="seoworkerai-input" name="site_settings_max_keyword_difficulty"
                    min="0" max="100"
                    value="<?php echo esc_attr((string) ($siteSeoSettings['max_keyword_difficulty'] ?? 100)); ?>">
                </div>
              </div>

              <?php if (! empty($siteSettingTemplates)) { ?>
              <div class="seoworkerai-field" style="margin-bottom:16px">
                <label class="seoworkerai-label">Strategy preset</label>
                <select class="seoworkerai-select" name="site_settings_template_id">
                  <option value="0">Keep current custom settings</option>
                  <?php foreach ($siteSettingTemplates as $template) {
                      if (! is_array($template)) {
                          continue;
                      } ?>
                    <option value="<?php echo esc_attr((string) ($template['id'] ?? 0)); ?>"
                      <?php selected((int) ($siteSeoSettings['template_id'] ?? 0), (int) ($template['id'] ?? 0)); ?>>
                      <?php echo esc_html((string) ($template['name'] ?? 'Strategy')); ?>
                    </option>
                  <?php } ?>
                </select>
              </div>
              <?php } else { ?>
                <input type="hidden" name="site_settings_template_id" value="0">
              <?php } ?>

              <div class="seoworkerai-btn-row">
                <?php if ($strategyDone) { ?>
                  <button type="submit" class="seoworkerai-btn seoworkerai-btn--primary">Save strategy</button>
                <?php } else { ?>
                  <button type="submit" class="seoworkerai-btn seoworkerai-btn--primary" data-seoworkerai-step-continue>
                    Save and continue →
                  </button>
                <?php } ?>
              </div>
            </form>
          </div>
        </div><!-- /step 1 -->

        <!-- STEP 2: Google connection -->
        <div class="seoworkerai-step seoworkerai-step <?php echo esc_attr($step2State); ?>" data-step="google">
          <div class="seoworkerai-step-header seoworkerai-step-header">
            <div class="seoworkerai-step-num">
              <span class="seoworkerai-step-num-digit">2</span>
              <svg class="seoworkerai-step-num-check" width="12" height="12" viewBox="0 0 12 12" fill="none">
                <path d="M2 6l3 3 5-5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </div>
            <div class="seoworkerai-step-info">
              <div class="seoworkerai-step-label">Google connection</div>
              <div class="seoworkerai-step-sublabel">
                <?php if ($googleDone) {
                    echo esc_html('Connected'.($oauthConnectedAt > 0 ? ' '.wp_date('Y-m-d', $oauthConnectedAt) : '').' · '.implode(', ', array_map('strval', $oauthScopes)));
                } elseif ($strategyDone) {
                    echo 'Authorise Search Console and Analytics';
                } else {
                    echo 'Complete strategy setup first';
                } ?>
              </div>
            </div>
            <div class="seoworkerai-step-right">
              <?php if ($googleDone) { ?>
                <button type="button" class="seoworkerai-step-edit-link seoworkerai-step-edit-link">Manage</button>
              <?php } ?>
              <?php if ($step2State !== 'is-pending') { ?>
              <span class="seoworkerai-step-chevron">
                <svg width="10" height="6" viewBox="0 0 10 6" fill="none">
                  <path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
              </span>
              <?php } ?>
            </div>
          </div>

          <div class="seoworkerai-step-body">
            <?php if (! $isConnected) { ?>
              <p style="font-size:13px;color:var(--ink-3);margin:18px 0 16px;line-height:1.6">
                SEOWorkerAI reads Search Console impressions and Analytics traffic to
                prioritise which pages to fix first. No data is stored beyond what's
                needed for recommendations.
              </p>
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('seoworkerai_start_oauth'); ?>
                <input type="hidden" name="action" value="seoworkerai_start_oauth">
                <button type="submit" class="seoworkerai-google-btn">
                  <svg width="18" height="18" viewBox="0 0 18 18" fill="none">
                    <path d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844c-.209 1.125-.843 2.078-1.796 2.717v2.258h2.908C16.658 14.215 17.64 11.907 17.64 9.2z" fill="#4285F4"/>
                    <path d="M9 18c2.43 0 4.467-.806 5.956-2.18l-2.908-2.259c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332C2.438 15.983 5.482 18 9 18z" fill="#34A853"/>
                    <path d="M3.964 10.71c-.18-.54-.282-1.117-.282-1.71s.102-1.17.282-1.71V4.958H.957C.347 6.173 0 7.548 0 9s.348 2.827.957 4.042l3.007-2.332z" fill="#FBBC05"/>
                    <path d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0 5.482 0 2.438 2.017.957 4.958L3.964 7.29C4.672 5.163 6.656 3.58 9 3.58z" fill="#EA4335"/>
                  </svg>
                  Connect Google account
                </button>
              </form>
            <?php } else { ?>
              <div style="margin-top:18px">
                <div class="seoworkerai-section">
                  <div class="seoworkerai-kv">
                    <div class="seoworkerai-kv-row">
                      <span class="seoworkerai-kv-key">Status</span>
                      <span class="seoworkerai-kv-val"><span class="seoworkerai-badge seoworkerai-badge--green">Connected</span></span>
                    </div>
                    <div class="seoworkerai-kv-row">
                      <span class="seoworkerai-kv-key">Scopes</span>
                      <span class="seoworkerai-kv-val"><?php echo esc_html(! empty($oauthScopes) ? implode(', ', array_map('strval', $oauthScopes)) : 'None'); ?></span>
                    </div>
                    <div class="seoworkerai-kv-row">
                      <span class="seoworkerai-kv-key">Connected</span>
                      <span class="seoworkerai-kv-val"><?php echo esc_html($oauthConnectedAt > 0 ? wp_date('Y-m-d H:i', $oauthConnectedAt) : '—'); ?></span>
                    </div>
                    <?php if ($oauthError !== '') { ?>
                      <div class="seoworkerai-kv-row">
                        <span class="seoworkerai-kv-key">Last error</span>
                        <span class="seoworkerai-kv-val" style="color:var(--red)"><?php echo esc_html($oauthError); ?></span>
                      </div>
                    <?php } ?>
                  </div>
                </div>
                <div class="seoworkerai-btn-row" style="margin-top:14px">
                  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('seoworkerai_start_oauth'); ?>
                    <input type="hidden" name="action" value="seoworkerai_start_oauth">
                    <button type="submit" class="seoworkerai-btn seoworkerai-btn--secondary">Reconnect</button>
                  </form>
                  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:8px;align-items:center">
                    <?php wp_nonce_field('seoworkerai_revoke_oauth'); ?>
                    <input type="hidden" name="action" value="seoworkerai_revoke_oauth">
                    <input type="text" name="revocation_reason" placeholder="Reason (optional)"
                      style="height:32px;padding:0 10px;border:1px solid var(--line);border-radius:var(--radius-sm);font-size:13px;width:180px;font-family:var(--font)">
                    <button type="submit" class="seoworkerai-btn seoworkerai-btn--danger">Disconnect</button>
                  </form>
                </div>
              </div>
            <?php } ?>

            <details class="seoworkerai-disclosure" style="margin-top:16px">
              <summary>Troubleshooting</summary>
              <div class="seoworkerai-disclosure-body" style="padding-top:14px">
                <div class="seoworkerai-btn-row" style="margin-top:0;margin-bottom:14px">
                  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('seoworkerai_health_check'); ?>
                    <input type="hidden" name="action" value="seoworkerai_health_check">
                    <button type="submit" class="seoworkerai-btn seoworkerai-btn--secondary seoworkerai-btn--sm">Run health check</button>
                  </form>
                  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('seoworkerai_rotate_token'); ?>
                    <input type="hidden" name="action" value="seoworkerai_rotate_token">
                    <button type="submit" class="seoworkerai-btn seoworkerai-btn--secondary seoworkerai-btn--sm">Rotate API token</button>
                  </form>
                  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('seoworkerai_register_site'); ?>
                    <input type="hidden" name="action" value="seoworkerai_register_site">
                    <button type="submit" class="seoworkerai-btn seoworkerai-btn--secondary seoworkerai-btn--sm">Sync registration</button>
                  </form>
                </div>
                <?php if ($lastCron > 0 || $lastUserSync > 0 || $lastBriefSync > 0) { ?>
                  <div class="seoworkerai-kv" style="font-size:12px">
                    <div class="seoworkerai-kv-row"><span class="seoworkerai-kv-key">Queue heartbeat</span><span class="seoworkerai-kv-val"><?php echo esc_html($lastCron > 0 ? wp_date('Y-m-d H:i', $lastCron) : 'Never'); ?></span></div>
                    <div class="seoworkerai-kv-row"><span class="seoworkerai-kv-key">User sync</span><span class="seoworkerai-kv-val"><?php echo esc_html($lastUserSync > 0 ? wp_date('Y-m-d H:i', $lastUserSync) : 'Never'); ?></span></div>
                    <div class="seoworkerai-kv-row"><span class="seoworkerai-kv-key">Brief sync</span><span class="seoworkerai-kv-val"><?php echo esc_html($lastBriefSync > 0 ? wp_date('Y-m-d H:i', $lastBriefSync) : 'Never'); ?></span></div>
                  </div>
                <?php } ?>
              </div>
            </details>
          </div>
        </div><!-- /step 2 -->

        <!-- STEP 3: Automation preferences -->
        <div class="seoworkerai-step seoworkerai-step <?php echo esc_attr($step3State); ?>" data-step="automation">
          <div class="seoworkerai-step-header seoworkerai-step-header">
            <div class="seoworkerai-step-num">
              <span class="seoworkerai-step-num-digit">3</span>
              <svg class="seoworkerai-step-num-check" width="12" height="12" viewBox="0 0 12 12" fill="none">
                <path d="M2 6l3 3 5-5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </div>
            <div class="seoworkerai-step-info">
              <div class="seoworkerai-step-label">Automation</div>
              <div class="seoworkerai-step-sublabel">
                <?php echo $mode === 'dangerous_auto_apply' ? 'Applying changes automatically' : 'Reviewing every change before applying'; ?>
              </div>
            </div>
            <div class="seoworkerai-step-right">
              <?php if ($step3State !== 'is-pending') { ?>
                <button type="button" class="seoworkerai-step-edit-link seoworkerai-step-edit-link">Edit</button>
                <span class="seoworkerai-step-chevron">
                  <svg width="10" height="6" viewBox="0 0 10 6" fill="none">
                    <path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                  </svg>
                </span>
              <?php } ?>
            </div>
          </div>

          <div class="seoworkerai-step-body">
            <form method="post" action="options.php" style="margin-top:18px">
              <?php settings_fields('seoworkerai_settings'); ?>
              <div class="seoworkerai-field">
                <label class="seoworkerai-label">Change application mode</label>
                <div class="seoworkerai-radio-group">
                  <label class="seoworkerai-radio-card <?php echo $mode === 'dangerous_auto_apply' ? 'is-selected' : ''; ?>">
                    <input type="radio" name="seoworkerai_change_application_mode" value="dangerous_auto_apply"
                      <?php checked($mode, 'dangerous_auto_apply'); ?>>
                    <div>
                      <div class="seoworkerai-radio-title">Apply changes automatically</div>
                      <div class="seoworkerai-radio-desc">Fixes are applied as found — no approval needed. Best for teams that trust the recommendations.</div>
                    </div>
                  </label>
                  <label class="seoworkerai-radio-card <?php echo $mode === 'review_before_apply' ? 'is-selected' : ''; ?>">
                    <input type="radio" name="seoworkerai_change_application_mode" value="review_before_apply"
                      <?php checked($mode, 'review_before_apply'); ?>>
                    <div>
                      <div class="seoworkerai-radio-title">Review every change before applying</div>
                      <div class="seoworkerai-radio-desc">Each fix waits in Change Center for your approval. More control, a little more work.</div>
                    </div>
                  </label>
                </div>
              </div>

              <details class="seoworkerai-disclosure" style="border:1px solid var(--line);border-radius:var(--radius-sm)">
                <summary style="padding:11px 14px;font-size:13px;font-weight:500;color:var(--ink-2)">Advanced preferences</summary>
                <div style="padding:0 14px 14px">
                  <div class="seoworkerai-field" style="margin-top:14px">
                    <label class="seoworkerai-label" for="seoworkerai_primary_seo_adapter">Primary SEO plugin</label>
                    <select class="seoworkerai-select" name="seoworkerai_primary_seo_adapter" id="seoworkerai_primary_seo_adapter">
                      <option value="auto" <?php selected($adapter, 'auto'); ?>>Auto detect</option>
                      <option value="yoast" <?php selected($adapter, 'yoast'); ?>>Yoast SEO</option>
                      <option value="rankmath" <?php selected($adapter, 'rankmath'); ?>>Rank Math</option>
                      <option value="aioseo" <?php selected($adapter, 'aioseo'); ?>>AIOSEO</option>
                      <option value="core" <?php selected($adapter, 'core'); ?>>WordPress Core</option>
                    </select>
                  </div>
                  <label class="seoworkerai-check-row">
                    <input type="checkbox" name="seoworkerai_debug_enabled" value="1" <?php checked((bool) get_option('seoworkerai_debug_enabled', false)); ?>>
                    Enable debug logging
                  </label>
                  <label class="seoworkerai-check-row">
                    <input type="checkbox" name="seoworkerai_allow_insecure_ssl" value="1" <?php checked((bool) get_option('seoworkerai_allow_insecure_ssl', false)); ?>>
                    Allow insecure SSL <em style="color:var(--ink-4)">(dev only)</em>
                  </label>
                  <div class="seoworkerai-field" style="margin-top:14px">
                    <label class="seoworkerai-label">Exclude pages from audits</label>
                    <div id="seoworkerai-exclusion-tag-ui" class="seoworkerai-excl-tag-ui">
                      <div class="seoworkerai-excl-chips"></div>
                      <div class="seoworkerai-excl-search-wrap">
                        <input type="text" class="seoworkerai-excl-search" placeholder="Search and add pages…" autocomplete="off">
                      </div>
                      <div class="seoworkerai-excl-dropdown">
                        <?php foreach ($allPosts as $postId) {
                            $postTitle = get_the_title($postId);
                            $typeLabel = get_post_type($postId) === 'page' ? 'Page' : 'Post';
                            $isSelected = in_array($postId, $excludedIds, true);
                            ?>
                          <div class="seoworkerai-excl-option <?php echo $isSelected ? 'is-selected' : ''; ?>"
                            data-id="<?php echo esc_attr((string) $postId); ?>"
                            data-label="<?php echo esc_attr(strtolower($postTitle)); ?>">
                            <span class="seoworkerai-excl-checkmark"><?php echo $isSelected ? '✓' : ''; ?></span>
                            <span class="seoworkerai-excl-type-tag"><?php echo esc_html($typeLabel); ?></span>
                            <?php echo esc_html($postTitle); ?>
                          </div>
                        <?php } ?>
                      </div>
                    </div>
                    <input type="hidden" name="seoworkerai_excluded_change_audit_pages"
                      id="seoworkerai-exclusion-hidden" value="<?php echo esc_attr($excludedRaw); ?>">
                  </div>
                </div>
              </details>

              <div class="seoworkerai-btn-row">
                <?php submit_button('Save preferences', 'seoworkerai-btn seoworkerai-btn--primary', 'submit', false); ?>
              </div>
            </form>
          </div>
        </div><!-- /step 3 -->

      </div><!-- /.seoworkerai-stepper-steps -->
    </div><!-- /.seoworkerai-stepper -->

    <!-- ═══════════════════════════════════════════════════════════════
         SITE SETUP — always available below the stepper
    ════════════════════════════════════════════════════════════════ -->
    <div class="seoworkerai-section" style="margin-bottom:16px">
      <div class="seoworkerai-section-header">
        <span class="seoworkerai-section-title">Site setup</span>
      </div>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('seoworkerai_update_site_profile'); ?>
        <input type="hidden" name="action" value="seoworkerai_update_site_profile">

        <div style="padding:16px 20px;border-bottom:1px solid var(--line-2)">
          <label class="seoworkerai-label" for="seoworkerai-site-description">Site description</label>
          <textarea id="seoworkerai-site-description" class="seoworkerai-textarea"
            name="site_profile_description" rows="3"><?php echo esc_textarea($siteDescription); ?></textarea>
          <span class="seoworkerai-hint">Helps SEOWorkerAI understand your audience and generate relevant briefs.</span>
        </div>

        <div style="padding:16px 20px;border-bottom:1px solid var(--line-2)">
          <label class="seoworkerai-label">Primary market</label>
          <div class="seoworkerai-locations-table-wrap" data-location-options="<?php echo esc_attr(wp_json_encode(array_values($availableLocations))); ?>">
            <table class="seoworkerai-locations-table">
              <thead><tr><th>Location</th><th>Code</th><th>Type</th><th></th></tr></thead>
              <tbody id="seoworkerai-locations-body">
                <?php foreach ($siteLocations as $index => $location) { ?>
                  <tr class="seoworkerai-location-row">
                    <td>
                      <select name="site_locations[<?php echo esc_attr((string) $index); ?>][location_code]" class="seoworkerai-location-select">
                        <?php foreach ($availableLocations as $option) { ?>
                          <option value="<?php echo esc_attr((string) $option['code']); ?>"
                            data-location-name="<?php echo esc_attr((string) $option['name']); ?>"
                            <?php selected((int) $location['location_code'], (int) $option['code']); ?>>
                            <?php echo esc_html((string) $option['label']); ?>
                          </option>
                        <?php } ?>
                      </select>
                      <input type="hidden" name="site_locations[<?php echo esc_attr((string) $index); ?>][location_name]"
                        value="<?php echo esc_attr((string) $location['location_name']); ?>" class="seoworkerai-location-name">
                    </td>
                    <td class="seoworkerai-location-code-cell" style="font-size:12px;color:var(--ink-3)"><?php echo esc_html((string) $location['location_code']); ?></td>
                    <td>
                      <select name="site_locations[<?php echo esc_attr((string) $index); ?>][location_type]" class="seoworkerai-location-type">
                        <option value="primary" <?php selected((string) $location['location_type'], 'primary'); ?>>Primary</option>
                        <option value="secondary" <?php selected((string) $location['location_type'], 'secondary'); ?>>Secondary</option>
                      </select>
                    </td>
                    <td><button type="button" class="seoworkerai-remove-location" style="font-size:12px;color:var(--red);background:none;border:none;cursor:pointer">Remove</button></td>
                  </tr>
                <?php } ?>
              </tbody>
            </table>
          </div>
          <button type="button" id="seoworkerai-add-location-row" class="seoworkerai-btn seoworkerai-btn--secondary seoworkerai-btn--sm" style="margin-top:8px">+ Add location</button>
        </div>

        <!-- Advanced SEO settings (collapsed) -->
        <details class="seoworkerai-disclosure">
          <summary>Advanced SEO settings</summary>
          <div class="seoworkerai-disclosure-body" style="padding-top:16px">
            <div class="seoworkerai-field">
              <label class="seoworkerai-label">Brand taste / voice</label>
              <textarea class="seoworkerai-textarea" name="site_profile_taste" rows="3"><?php echo esc_textarea($siteTaste); ?></textarea>
            </div>
            <div class="seoworkerai-fieldset">
              <div class="seoworkerai-fieldset-label">Content brief settings</div>
              <!-- domain_rating, min/max_search_volume are set in the Strategy step above — hidden fields keep them from being blanked on save -->
              <input type="hidden" name="site_settings_domain_rating"
                value="<?php echo esc_attr($domainRatingInputValue); ?>">
              <input type="hidden" name="site_settings_min_search_volume"
                value="<?php echo esc_attr((string) ($siteSeoSettings['min_search_volume'] ?? 0)); ?>">
              <input type="hidden" name="site_settings_max_search_volume"
                value="<?php echo esc_attr(($siteSeoSettings['max_search_volume'] ?? null) === null ? '' : (string) $siteSeoSettings['max_search_volume']); ?>">
              <div class="seoworkerai-grid-2" style="margin-bottom:14px">
                <div class="seoworkerai-field">
                  <label class="seoworkerai-label">Max keyword difficulty</label>
                  <input type="number" class="seoworkerai-input" min="0" max="100"
                    id="seoworkerai-site-settings-max-keyword-difficulty"
                    name="site_settings_max_keyword_difficulty"
                    value="<?php echo esc_attr((string) ($siteSeoSettings['max_keyword_difficulty'] ?? 100)); ?>">
                </div>
                <div class="seoworkerai-field">
                  <label class="seoworkerai-label">Content briefs per run</label>
                  <input type="number" class="seoworkerai-input" min="1" max="10"
                    id="seoworkerai-site-settings-content-briefs-per-run"
                    name="site_settings_content_briefs_per_run"
                    value="<?php echo esc_attr((string) ($siteSeoSettings['content_briefs_per_run'] ?? 3)); ?>">
                </div>
              </div>
              <div class="seoworkerai-grid-2" style="margin-bottom:14px">
                <div class="seoworkerai-field">
                  <label class="seoworkerai-label">Strategy preset</label>
                  <select class="seoworkerai-select" id="seoworkerai-site-settings-template-id" name="site_settings_template_id"
                    data-template-configs="<?php echo esc_attr(wp_json_encode(array_map(fn (array $t): array => [
                        'id' => (int) ($t['id'] ?? 0),
                        'min_search_volume' => (int) ($t['min_search_volume'] ?? 0),
                        'max_search_volume' => ($t['max_search_volume'] ?? null) !== null ? (int) $t['max_search_volume'] : null,
                        'max_keyword_difficulty' => (int) ($t['max_keyword_difficulty'] ?? 100),
                        'preferred_keyword_type' => (string) ($t['preferred_keyword_type'] ?? ''),
                        'content_briefs_per_run' => (int) ($t['content_briefs_per_run'] ?? 3),
                        'prefer_low_difficulty' => ! empty($t['prefer_low_difficulty']),
                        'allow_low_volume' => ! empty($t['allow_low_volume']),
                        'selection_notes' => (string) ($t['selection_notes'] ?? ''),
                    ], $siteSettingTemplates))); ?>">
                    <option value="0">Keep current custom settings</option>
                    <?php foreach ($siteSettingTemplates as $template) {
                        if (! is_array($template)) {
                            continue;
                        } ?>
                      <option value="<?php echo esc_attr((string) ($template['id'] ?? 0)); ?>"
                        <?php selected((int) ($siteSeoSettings['template_id'] ?? 0), (int) ($template['id'] ?? 0)); ?>>
                        <?php echo esc_html((string) ($template['name'] ?? 'Template')); ?>
                      </option>
                    <?php } ?>
                  </select>
                </div>
                <div class="seoworkerai-field">
                  <label class="seoworkerai-label">Preferred keyword type</label>
                  <select class="seoworkerai-select" id="seoworkerai-site-settings-preferred-keyword-type" name="site_settings_preferred_keyword_type">
                    <option value="">Auto</option>
                    <?php foreach (['informational', 'commercial', 'transactional', 'navigational'] as $ktype) { ?>
                      <option value="<?php echo esc_attr($ktype); ?>" <?php selected((string) ($siteSeoSettings['preferred_keyword_type'] ?? ''), $ktype); ?>><?php echo esc_html(ucwords($ktype)); ?></option>
                    <?php } ?>
                  </select>
                </div>
              </div>
              <div class="seoworkerai-grid-2" style="margin-bottom:14px">
                <div class="seoworkerai-field">
                  <label class="seoworkerai-label">Brand Twitter/X handle</label>
                  <input type="text" class="seoworkerai-input" name="site_settings_brand_twitter_handle"
                    value="<?php echo esc_attr((string) ($siteSeoSettings['brand_twitter_handle'] ?? '')); ?>" placeholder="@yourbrand">
                  <span class="seoworkerai-hint">Used as default twitter:site value site-wide.</span>
                </div>
                <div class="seoworkerai-field">
                  <label class="seoworkerai-label">Default social image URL</label>
                  <input type="url" class="seoworkerai-input" name="site_settings_default_social_image_url"
                    value="<?php echo esc_attr((string) ($siteSeoSettings['default_social_image_url'] ?? '')); ?>"
                    placeholder="https://example.com/og-image.jpg">
                  <span class="seoworkerai-hint">Fallback OG/Twitter image.</span>
                </div>
              </div>
              <div class="seoworkerai-field">
                <label class="seoworkerai-label">Selection notes</label>
                <textarea class="seoworkerai-textarea" id="seoworkerai-site-settings-selection-notes"
                  name="site_settings_selection_notes" rows="3"><?php echo esc_textarea((string) ($siteSeoSettings['selection_notes'] ?? '')); ?></textarea>
              </div>
              <label class="seoworkerai-check-row"><input type="checkbox" name="site_settings_prefer_low_difficulty" value="1" <?php checked(! empty($siteSeoSettings['prefer_low_difficulty'])); ?>> Prefer easier keywords first</label>
              <label class="seoworkerai-check-row"><input type="checkbox" name="site_settings_allow_low_volume" value="1" <?php checked(! empty($siteSeoSettings['allow_low_volume'])); ?>> Allow low-volume opportunities</label>
            </div>
          </div>
        </details>

        <!-- Author profiles (collapsed) -->
        <details class="seoworkerai-disclosure" id="author-social-profiles">
          <summary>Author social profiles</summary>
          <div class="seoworkerai-disclosure-body" style="padding-top:14px">
            <p style="font-size:13px;color:var(--ink-3);margin:0 0 12px;line-height:1.6">
              Add author handles once so article audits stop asking page by page.
            </p>
            <?php if ($authorProfiles !== []) { ?>
              <div style="margin-bottom:10px;display:flex;gap:8px;align-items:center">
                <input type="search" id="seoworkerai-author-search" placeholder="Search author or email…"
                  style="height:30px;padding:0 10px;border:1px solid var(--line);border-radius:var(--radius-sm);font-size:13px;max-width:260px;font-family:var(--font)">
                <div id="seoworkerai-author-pagination" style="font-size:12px;color:var(--ink-3)"></div>
              </div>
              <div style="overflow-x:auto">
                <table class="seoworkerai-locations-table" id="seoworkerai-author-table" data-page-size="10">
                  <thead>
                    <tr>
                      <th><button type="button" style="background:none;border:none;cursor:pointer;font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.5px;color:var(--ink-3);font-family:var(--font)" data-sort-key="author">Author</button></th>
                      <th><button type="button" style="background:none;border:none;cursor:pointer;font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.5px;color:var(--ink-3);font-family:var(--font)" data-sort-key="email">Email</button></th>
                      <th>Twitter/X</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($authorProfiles as $ap) { ?>
                      <tr data-author="<?php echo esc_attr(strtolower((string) $ap['display_name'])); ?>"
                          data-email="<?php echo esc_attr(strtolower((string) $ap['email'])); ?>">
                        <td><?php echo esc_html((string) $ap['display_name']); ?></td>
                        <td style="color:var(--ink-3)"><?php echo esc_html((string) $ap['email']); ?></td>
                        <td>
                          <input type="text" class="seoworkerai-input"
                            name="author_profiles[<?php echo esc_attr((string) $ap['user_id']); ?>][twitter_handle]"
                            value="<?php echo esc_attr((string) $ap['twitter_handle']); ?>"
                            placeholder="@authorhandle"
                            style="font-size:12px;padding:5px 8px">
                        </td>
                      </tr>
                    <?php } ?>
                  </tbody>
                </table>
              </div>
            <?php } else { ?>
              <p style="font-size:13px;color:var(--ink-4);margin:0">No authors found on this site.</p>
            <?php } ?>
          </div>
        </details>

        <div style="padding:16px 20px">
          <button type="submit" class="seoworkerai-btn seoworkerai-btn--primary">Save site setup</button>
        </div>
      </form>
    </div><!-- /.seoworkerai-section site setup -->

    <!-- ═══ CONNECTION OVERVIEW ══════════════════════════════════════ -->
    <div class="seoworkerai-section" style="margin-top:16px">
      <div class="seoworkerai-section-header">
        <span class="seoworkerai-section-title">Connection overview</span>
      </div>
      <div class="seoworkerai-kv">
        <div class="seoworkerai-kv-row">
          <span class="seoworkerai-kv-key">Registration</span>
          <span class="seoworkerai-kv-val">
            <?php if ($siteId > 0) { ?>
              <span class="seoworkerai-badge seoworkerai-badge--green">Active (ID: <?php echo esc_html((string) $siteId); ?>)</span>
            <?php } else { ?>
              <span class="seoworkerai-badge seoworkerai-badge--gray">Not registered</span>
            <?php } ?>
          </span>
        </div>
        <div class="seoworkerai-kv-row">
          <span class="seoworkerai-kv-key">Google</span>
          <span class="seoworkerai-kv-val">
            <?php if ($isConnected) { ?>
              <span class="seoworkerai-badge seoworkerai-badge--green">Connected<?php echo $oauthProvider !== '' ? ' ('.esc_html($oauthProvider).')' : ''; ?></span>
            <?php } else { ?>
              <span class="seoworkerai-badge seoworkerai-badge--gray">Not connected</span>
            <?php } ?>
          </span>
        </div>
        <div class="seoworkerai-kv-row">
          <span class="seoworkerai-kv-key">Domain rating confirmed</span>
          <span class="seoworkerai-kv-val">
            <?php if ($domainRatingConfirmed) { ?>
              <span class="seoworkerai-badge seoworkerai-badge--green">Yes</span>
            <?php } else { ?>
              <span class="seoworkerai-badge seoworkerai-badge--gray">Not yet</span>
            <?php } ?>
          </span>
        </div>
      </div>
    </div>

  </div><!-- /.seoworkerai-body -->

</div><!-- /.seoworkerai-page -->
        <?php
    }

    // =========================================================================
    // renderAdminShellHeader — used by Activity, Change Center, Action Items,
    // Content Briefs, Schedules, Debug Logs, OAuth Callback.
    // UPDATED: uses new seoworkerai-shell CSS classes so the nav looks identical on all
    // pages. The $title / $activePage / $description params are unchanged.
    // =========================================================================

    private function renderAdminShellHeader(string $title, string $activePage, string $description = ''): void
    {
        $tabs = [
            'seoworkerai' => ['label' => 'Settings',       'cap' => 'manage_options'],
            'seoworkerai-logs' => ['label' => 'Change Center',  'cap' => 'manage_options'],
            'seoworkerai-action-items' => ['label' => 'Action Items',   'cap' => 'manage_options'],
            'seoworkerai-briefs' => ['label' => 'Content Briefs', 'cap' => 'edit_posts'],
        ];
        ?>
<div class="seoworkerai-page">
  <nav class="seoworkerai-shell">
    <div class="seoworkerai-shell-brand">
      <div class="seoworkerai-shell-brand-mark">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
          <rect x="2" y="2" width="5" height="5" rx="1" fill="white" opacity=".9"/>
          <rect x="9" y="2" width="5" height="5" rx="1" fill="white" opacity=".6"/>
          <rect x="2" y="9" width="5" height="5" rx="1" fill="white" opacity=".6"/>
          <rect x="9" y="9" width="5" height="5" rx="1" fill="white" opacity=".3"/>
        </svg>
      </div>
      <span class="seoworkerai-shell-name">SEOWorkerAI</span>
    </div>
    <div class="seoworkerai-tabs">
      <?php foreach ($tabs as $slug => $tab) {
          if (! current_user_can((string) $tab['cap'])) {
              continue;
          }
          $isActive = ($slug === $activePage || ($slug === 'seoworkerai' && $activePage === 'seoworkerai-settings'));
          ?>
          <a class="seoworkerai-tab <?php echo $isActive ? 'is-active' : ''; ?>"
             href="<?php echo esc_url(admin_url('admin.php?page='.$slug)); ?>">
            <?php echo esc_html((string) $tab['label']); ?>
          </a>
      <?php } ?>
    </div>
  </nav>
  <div class="seoworkerai-body">
    <?php if ($title !== '' || $description !== '') { ?>
      <h1 class="seoworkerai-page-title"><?php echo esc_html($title); ?></h1>
      <?php if ($description !== '') { ?>
        <p class="seoworkerai-page-desc"><?php echo esc_html($description); ?></p>
      <?php } ?>
    <?php } ?>
        <?php
        // Note: the caller must close </div></div> at the end of their page output.
        // Each render* method ends with the same two closing tags as before:
        //   </div><!-- /.seoworkerai-body -->
        //   </div><!-- /.seoworkerai-page -->
        // which are emitted by renderAdminShellFooter() below.
    }

    /**
     * Closes the seoworkerai-body / seoworkerai-page divs opened by renderAdminShellHeader().
     * Call this at the END of every page that uses renderAdminShellHeader().
     */
    private function renderAdminShellFooter(): void
    {
        echo '</div><!-- /.seoworkerai-body -->'."\n".'</div><!-- /.seoworkerai-page -->'."\n";
    }

    // =========================================================================
    // renderActivityPage — UNCHANGED logic, updated shell calls
    // =========================================================================

    public function renderActivityPage(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        global $wpdb;
        $actionsTable = $wpdb->prefix.'seoworkerai_actions';
        $itemsTable = $wpdb->prefix.'seoworkerai_action_items';
        $siteId = (int) get_option('seoworkerai_site_id', 0);
        $notice = isset($_GET['seoworkerai_notice']) ? sanitize_text_field((string) $_GET['seoworkerai_notice']) : '';
        $executionLogs = [];
        $scheduledTasks = [];
        $remoteErrors = [];

        if ($siteId > 0 && $this->tokenManager->hasToken()) {
            try {
                $logsResponse = $this->client->listExecutionLogsFast(['limit' => 8]);
                $executionLogs = isset($logsResponse['execution_logs']) && is_array($logsResponse['execution_logs']) ? $logsResponse['execution_logs'] : [];
            } catch (\Throwable $e) {
                $remoteErrors[] = $e->getMessage();
            }
            try {
                $scheduledResponse = $this->client->listScheduledTasksFast(['limit' => 8]);
                $scheduledTasks = isset($scheduledResponse['scheduled_tasks']) && is_array($scheduledResponse['scheduled_tasks']) ? $scheduledResponse['scheduled_tasks'] : [];
            } catch (\Throwable $e) {
                $remoteErrors[] = $e->getMessage();
            }
        }

        $openItems = $siteId > 0 ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$itemsTable} WHERE site_id = %d AND status != %s", $siteId, 'resolved')) : 0; // phpcs:ignore
        $appliedChanges = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$actionsTable} WHERE status = 'applied'"); // phpcs:ignore
        $failedChanges = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$actionsTable} WHERE status IN ('failed','ack_failed')"); // phpcs:ignore
        $queuedChanges = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$actionsTable} WHERE status IN ('received','queued','running','ack_pending')"); // phpcs:ignore

        $this->renderAdminShellHeader('Activity', 'seoworkerai-activity', 'See what was checked, what was fixed, and what still needs your input.');
        $this->renderNotice($notice);
        if (! empty($remoteErrors)) {
            echo '<div class="notice notice-warning"><p>Some remote activity data could not be loaded: '.esc_html(implode(' | ', $remoteErrors)).'</p></div>';
        }
        ?>
        <div class="seoworkerai-stat-grid" style="margin-bottom:16px">
          <div class="seoworkerai-stat"><span>Needs Your Input</span><strong><?php echo esc_html((string) $openItems); ?></strong></div>
          <div class="seoworkerai-stat"><span>Fixed Automatically</span><strong><?php echo esc_html((string) $appliedChanges); ?></strong></div>
          <div class="seoworkerai-stat"><span>Currently Processing</span><strong><?php echo esc_html((string) $queuedChanges); ?></strong></div>
          <div class="seoworkerai-stat"><span>Needs Review</span><strong><?php echo esc_html((string) $failedChanges); ?></strong></div>
        </div>

        <div class="seoworkerai-settings-grid">
          <section class="seoworkerai-card">
            <div class="seoworkerai-card-head">
              <h2>Needs Your Input</h2>
              <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=seoworkerai-action-items')); ?>">Open Action Items</a>
            </div>
            <p>These are blockers where automation is waiting on business or author information.</p>
            <ul style="margin:0 0 0 18px">
              <li>Site-wide defaults (brand Twitter/X, social image) → Settings → Site Setup</li>
              <li>Author handles → Settings → Author Social Profiles</li>
              <li>Page-specific exceptions → Action Items</li>
            </ul>
          </section>
          <section class="seoworkerai-card">
            <div class="seoworkerai-card-head">
              <h2>Recent Runs</h2>
              <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=seoworkerai-schedules')); ?>">Open Schedules</a>
            </div>
            <?php if ($executionLogs !== []) { ?>
              <div class="seoworkerai-table-wrap">
                <table class="wp-list-table widefat">
                  <thead><tr><th>Task</th><th>Status</th><th>When</th><th>Source</th></tr></thead>
                  <tbody>
                    <?php foreach ($executionLogs as $log) { ?>
                      <tr>
                        <td><?php echo esc_html((string) ($log['task_name'] ?? $log['type'] ?? 'Task')); ?></td>
                        <td><?php echo wp_kses_post($this->renderStatusBadge((string) ($log['status'] ?? 'unknown'))); ?></td>
                        <td><?php echo esc_html((string) ($log['completed_at'] ?? $log['created_at'] ?? '')); ?></td>
                        <td><?php echo esc_html((string) ($log['trigger_source'] ?? 'run')); ?></td>
                      </tr>
                    <?php } ?>
                  </tbody>
                </table>
              </div>
            <?php } else { ?>
              <p class="seoworkerai-muted">No recent execution logs.</p>
            <?php } ?>
          </section>
        </div>

        <div class="seoworkerai-card" style="margin-top:16px">
          <div class="seoworkerai-card-head">
            <h2>Upcoming Scheduled Work</h2>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=seoworkerai-schedules')); ?>">Manage Schedules</a>
          </div>
          <?php if ($scheduledTasks !== []) { ?>
            <div class="seoworkerai-table-wrap">
              <table class="wp-list-table widefat">
                <thead><tr><th>Task</th><th>Status</th><th>Scheduled For</th><th>Trigger</th></tr></thead>
                <tbody>
                  <?php foreach ($scheduledTasks as $task) { ?>
                    <tr>
                      <td><?php echo esc_html((string) ($task['task_name'] ?? 'Task')); ?></td>
                      <td><?php echo wp_kses_post($this->renderStatusBadge((string) ($task['status'] ?? 'scheduled'))); ?></td>
                      <td><?php echo esc_html((string) ($task['scheduled_for'] ?? '')); ?></td>
                      <td><?php echo esc_html((string) ($task['trigger_source'] ?? 'scheduled')); ?></td>
                    </tr>
                  <?php } ?>
                </tbody>
              </table>
            </div>
          <?php } else { ?>
            <p class="seoworkerai-muted">No upcoming scheduled tasks.</p>
          <?php } ?>
        </div>
        <?php
        $this->renderAdminShellFooter();
    }

    // =========================================================================
    // renderLogsPage (Change Center) — UNCHANGED logic, updated shell calls
    // =========================================================================

    public function renderLogsPage(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $notice = isset($_GET['seoworkerai_notice']) ? sanitize_text_field((string) $_GET['seoworkerai_notice']) : '';
        $statusArr = isset($_GET['status']) ? array_filter(array_map('sanitize_text_field', (array) $_GET['status'])) : [];
        $actionTypeArr = isset($_GET['action_type']) ? array_filter(array_map('sanitize_text_field', (array) $_GET['action_type'])) : [];
        $targetTypeArr = isset($_GET['target_type']) ? array_filter(array_map('sanitize_text_field', (array) $_GET['target_type'])) : [];
        $postIdArr = isset($_GET['post_id']) ? array_filter(array_map('intval', (array) $_GET['post_id'])) : [];
        $search = isset($_GET['q']) ? sanitize_text_field((string) $_GET['q']) : '';
        $page = max(1, (int) ($_GET['paged'] ?? 1));
        $perPage = min(100, max(10, (int) ($_GET['per_page'] ?? 10)));
        $offset = ($page - 1) * $perPage;

        $filters = [];
        if (! empty($statusArr)) {
            $filters['status'] = $statusArr;
        }
        if (! empty($actionTypeArr)) {
            $filters['action_type'] = $actionTypeArr;
        }
        if (! empty($targetTypeArr)) {
            $filters['target_type'] = $targetTypeArr;
        }
        if (! empty($postIdArr)) {
            $filters['post_ids'] = $postIdArr;
        }
        if ($search !== '') {
            $filters['search'] = $search;
        }

        $totalActions = $this->actionRepository->countActions($filters);
        $totalPages = max(1, (int) ceil($totalActions / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $perPage;
        }

        $actions = $this->actionRepository->listActions($filters, $perPage, $offset);
        $actionTypeOptions = $this->actionRepository->listDistinctActionTypes();
        $targetTypeOptions = $this->actionRepository->listDistinctTargetTypes();

        $siteId = (int) get_option('seoworkerai_site_id', 0);
        global $wpdb;
        $itemsTable = $wpdb->prefix.'seoworkerai_action_items';
        $humanItems = $siteId > 0
            ? $wpdb->get_results($wpdb->prepare("SELECT * FROM {$itemsTable} WHERE site_id = %d ORDER BY updated_at DESC LIMIT 200", $siteId), ARRAY_A) // phpcs:ignore
            : [];
        if (! is_array($humanItems)) {
            $humanItems = [];
        }
        $openHumanItems = count(array_filter($humanItems, fn ($i) => ($i['status'] ?? '') !== 'resolved'));

        $actionIdsOnPage = array_values(array_filter(array_map(static fn (array $row): int => (int) ($row['laravel_action_id'] ?? 0), $actions), static fn (int $id): bool => $id > 0));
        $changeLogs = $this->actionRepository->listChangeLogsForLaravelIds($actionIdsOnPage, 500, ['exclude_event_type' => 'human_action_created']);
        $groupedChangeLogs = [];
        foreach ($changeLogs as $log) {
            $lid = (int) ($log['laravel_action_id'] ?? 0);
            if ($lid <= 0) {
                continue;
            }
            if (! isset($groupedChangeLogs[$lid])) {
                $groupedChangeLogs[$lid] = ['events' => []];
            }
            $groupedChangeLogs[$lid]['events'][] = $log;
        }

        $allPosts = get_posts(['post_type' => ['post', 'page'], 'post_status' => 'publish', 'posts_per_page' => 500, 'orderby' => 'title', 'order' => 'ASC', 'fields' => 'ids']);
        $labelMapsJson = wp_json_encode([
            'status' => ['received' => 'Received', 'queued' => 'Queued', 'running' => 'Running', 'applied' => 'Applied', 'failed' => 'Failed', 'rolled_back' => 'Rolled Back'],
            'action_type' => array_combine($actionTypeOptions, $actionTypeOptions),
            'target_type' => array_combine($targetTypeOptions, $targetTypeOptions),
            'post_id' => array_combine(array_map('strval', $allPosts), array_map(fn ($pid) => (string) get_the_title($pid), $allPosts)),
        ]);

        $this->renderAdminShellHeader('Change Center', 'seoworkerai-logs', 'Review automated SEO changes and their execution history.');
        $this->renderNotice($notice);
        ?>
        <div class="seoworkerai-kpi-grid">
          <div class="seoworkerai-kpi-card"><span class="seoworkerai-kpi-label">Total Actions</span><span class="seoworkerai-kpi-value"><?php echo esc_html((string) $totalActions); ?></span></div>
          <div class="seoworkerai-kpi-card"><span class="seoworkerai-kpi-label">On This Page</span><span class="seoworkerai-kpi-value"><?php echo esc_html((string) count($actions)); ?></span></div>
          <div class="seoworkerai-kpi-card"><span class="seoworkerai-kpi-label">Human Items</span><span class="seoworkerai-kpi-value"><?php echo esc_html((string) count($humanItems)); ?></span></div>
          <div class="seoworkerai-kpi-card"><span class="seoworkerai-kpi-label">Open Items</span><span class="seoworkerai-kpi-value"><?php echo esc_html((string) $openHumanItems); ?></span></div>
        </div>

        <div class="seoworkerai-chip-filter-bar" id="seoworkerai-filter-bar" data-label-maps="<?php echo esc_attr($labelMapsJson); ?>">
          <form method="get" class="seoworkerai-filter-form" id="seoworkerai-filter-form">
            <input type="hidden" name="page" value="seoworkerai-logs">
            <input type="hidden" name="per_page" value="<?php echo esc_attr((string) $perPage); ?>">
            <div class="seoworkerai-active-chips" id="seoworkerai-active-chips"></div>
            <div class="seoworkerai-filter-dropdowns">
              <?php
              $filterDefs = [
                  ['key' => 'status',      'label' => 'Status',      'options' => ['received' => 'Received', 'queued' => 'Queued', 'running' => 'Running', 'applied' => 'Applied', 'failed' => 'Failed', 'rolled_back' => 'Rolled Back']],
                  ['key' => 'action_type', 'label' => 'Action Type', 'options' => array_combine($actionTypeOptions, $actionTypeOptions)],
                  ['key' => 'target_type', 'label' => 'Target Type', 'options' => array_combine($targetTypeOptions, $targetTypeOptions)],
              ];
        foreach ($filterDefs as $fd) {
            $activeVals = match ($fd['key']) {
                'status' => $statusArr, 'action_type' => $actionTypeArr, 'target_type' => $targetTypeArr, default => []
            };
            ?>
                  <div class="seoworkerai-filter-dropdown" data-filter-key="<?php echo esc_attr($fd['key']); ?>">
                    <button type="button" class="seoworkerai-filter-btn <?php echo ! empty($activeVals) ? 'has-active' : ''; ?>">
                      <?php echo esc_html($fd['label']); ?>
                      <?php if (! empty($activeVals)) {
                          echo '<span class="seoworkerai-filter-count">'.count($activeVals).'</span>';
                      } ?>
                      <span class="seoworkerai-filter-chevron">▾</span>
                    </button>
                    <div class="seoworkerai-filter-panel" style="display:none">
                      <div class="seoworkerai-filter-panel-inner">
                        <?php foreach ($fd['options'] as $val => $label) { ?>
                          <label class="seoworkerai-filter-option">
                            <input type="checkbox" name="<?php echo esc_attr($fd['key']); ?>[]" value="<?php echo esc_attr((string) $val); ?>" <?php checked(in_array((string) $val, $activeVals, true)); ?>>
                            <?php echo esc_html((string) $label); ?>
                          </label>
                        <?php } ?>
                      </div>
                      <div class="seoworkerai-filter-panel-footer">
                        <button type="button" class="seoworkerai-filter-clear-one button-link" data-filter-key="<?php echo esc_attr($fd['key']); ?>">Clear</button>
                        <button type="button" class="button button-small button-primary seoworkerai-filter-apply">Apply</button>
                      </div>
                    </div>
                  </div>
              <?php } ?>
              <div class="seoworkerai-filter-dropdown" data-filter-key="post_id">
                <button type="button" class="seoworkerai-filter-btn <?php echo ! empty($postIdArr) ? 'has-active' : ''; ?>">
                  Page<?php if (! empty($postIdArr)) {
                      echo '<span class="seoworkerai-filter-count">'.count($postIdArr).'</span>';
                  } ?><span class="seoworkerai-filter-chevron">▾</span>
                </button>
                <div class="seoworkerai-filter-panel seoworkerai-filter-panel--wide" style="display:none">
                  <div class="seoworkerai-filter-panel-search"><input type="text" placeholder="Search pages…" class="seoworkerai-filter-post-search" autocomplete="off"></div>
                  <div class="seoworkerai-filter-panel-inner" style="max-height:200px;overflow-y:auto">
                    <?php foreach ($allPosts as $pid) {
                        $pt = get_post_type($pid) === 'page' ? 'Page' : 'Post';
                        $ptitle = get_the_title($pid);
                        ?>
                        <label class="seoworkerai-filter-option" data-label="<?php echo esc_attr(strtolower($ptitle)); ?>">
                          <input type="checkbox" name="post_id[]" value="<?php echo esc_attr((string) $pid); ?>" <?php checked(in_array($pid, $postIdArr, true)); ?>>
                          <span class="seoworkerai-filter-type-tag"><?php echo esc_html($pt); ?></span>
                          <?php echo esc_html($ptitle); ?>
                        </label>
                    <?php } ?>
                  </div>
                  <div class="seoworkerai-filter-panel-footer">
                    <button type="button" class="seoworkerai-filter-clear-one button-link" data-filter-key="post_id">Clear</button>
                    <button type="button" class="button button-small button-primary seoworkerai-filter-apply">Apply</button>
                  </div>
                </div>
              </div>
              <div style="display:flex;gap:6px;align-items:center;margin-left:auto">
                <input type="text" name="q" value="<?php echo esc_attr($search); ?>" placeholder="Search keyword…"
                  style="height:30px;padding:0 10px;border:1px solid var(--line);border-radius:var(--radius-sm);font-size:13px;width:180px;font-family:var(--font)">
                <button class="button button-primary" type="submit">Search</button>
                <?php if (! empty($statusArr) || ! empty($actionTypeArr) || ! empty($targetTypeArr) || ! empty($postIdArr) || $search !== '') { ?>
                  <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=seoworkerai-logs')); ?>">Reset</a>
                <?php } ?>
              </div>
            </div>
            <div id="seoworkerai-filter-hidden-inputs" class="seoworkerai-filter-hidden-inputs"></div>
          </form>
        </div>

        <div class="seoworkerai-card" style="padding:0;overflow:hidden">
          <div class="seoworkerai-table-wrap">
            <table class="wp-list-table widefat seoworkerai-changes-table">
              <thead>
                <tr>
                  <th style="min-width:200px">Title / Target</th>
                  <th style="min-width:110px">Action Type</th>
                  <th style="min-width:90px">Status</th>
                  <th style="min-width:220px">Proposed Change</th>
                  <th style="min-width:120px">Received</th>
                  <th style="min-width:100px">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php if (! empty($actions)) { ?>
                <?php foreach ($actions as $row) {
                    $laravelId = (int) ($row['laravel_action_id'] ?? 0);
                    $rowStatus = (string) ($row['status'] ?? 'received');
                    $isApplied = $rowStatus === 'applied';
                    $actionPayload = json_decode((string) ($row['action_payload'] ?? '{}'), true);
                    $beforeSnapshot = json_decode((string) ($row['before_snapshot'] ?? '{}'), true);
                    if (! is_array($actionPayload)) {
                        $actionPayload = [];
                    }
                    if (! is_array($beforeSnapshot)) {
                        $beforeSnapshot = [];
                    }
                    $actionTitle = $this->buildActionDisplayTitle($row, $actionPayload);
                    $editableFields = $this->buildEditableFields((string) ($row['action_type'] ?? ''), $actionPayload);
                    $proposedFields = $this->buildReadOnlyFields((string) ($row['action_type'] ?? ''), $actionPayload, [], []);
                    $currentFields = $this->buildReadOnlyFields((string) ($row['action_type'] ?? ''), $beforeSnapshot, [], []);
                    $hasLogs = isset($groupedChangeLogs[$laravelId]) && ! empty($groupedChangeLogs[$laravelId]['events']);
                    ?>
                    <tr>
                      <td>
                        <strong><?php echo esc_html($actionTitle); ?></strong>
                        <?php if (! empty($row['target_url'])) { ?>
                          <div class="seoworkerai-muted seoworkerai-truncate" style="max-width:200px" title="<?php echo esc_attr((string) $row['target_url']); ?>">
                            <a href="<?php echo esc_url((string) $row['target_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html((string) $row['target_url']); ?></a>
                          </div>
                        <?php } ?>
                        <?php if ($hasLogs) { ?>
                          <button type="button" class="seoworkerai-progression-toggle"
                            data-target="progression-<?php echo esc_attr((string) $laravelId); ?>">
                            <span class="seoworkerai-prog-arrow">▸</span> progression
                          </button>
                        <?php } ?>
                      </td>
                      <td><code style="font-size:11px"><?php echo esc_html((string) ($row['action_type'] ?? '')); ?></code></td>
                      <td><?php echo wp_kses_post($this->renderStatusBadge($rowStatus)); ?></td>
                      <td>
                        <div class="seoworkerai-inline-edit-container">
                          <div class="seoworkerai-inline-display">
                            <?php if (! empty($proposedFields)) { ?>
                              <?php foreach ($proposedFields as $field) { ?>
                                <div style="margin-bottom:4px">
                                  <div class="seoworkerai-field-label"><?php echo esc_html((string) ($field['label'] ?? '')); ?></div>
                                  <div class="seoworkerai-field-value"><?php echo esc_html((string) ($field['value'] ?? '')); ?></div>
                                </div>
                              <?php } ?>
                              <?php if (! empty($currentFields) && $currentFields !== $proposedFields) { ?>
                                <button type="button" class="seoworkerai-toggle-current">▸ Currently applied</button>
                                <div class="seoworkerai-current-value" style="display:none">
                                  <?php foreach ($currentFields as $cf) { ?>
                                    <div class="seoworkerai-field-value" style="color:var(--ink-3)"><?php echo esc_html((string) ($cf['value'] ?? '')); ?></div>
                                  <?php } ?>
                                </div>
                              <?php } ?>
                            <?php } else { ?>
                              <span class="seoworkerai-muted">—</span>
                            <?php } ?>
                          </div>
                          <?php if (! empty($editableFields)) { ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                              <?php wp_nonce_field('seoworkerai_edit_action_payload'); ?>
                              <input type="hidden" name="action" value="seoworkerai_edit_action_payload">
                              <input type="hidden" name="action_id" value="<?php echo esc_attr((string) $laravelId); ?>">
                              <div class="seoworkerai-inline-edit-actions">
                                <button type="button" class="button button-small" data-seoworkerai-edit-toggle="1">Edit</button>
                                <button type="submit" class="button button-small button-primary" data-seoworkerai-save-button="1" style="display:none">Save</button>
                                <button type="button" class="button button-small" data-seoworkerai-cancel-button="1" style="display:none">Cancel</button>
                              </div>
                              <div class="seoworkerai-inline-edit-fields">
                                <?php foreach ($editableFields as $field) {
                                    $fk = (string) ($field['key'] ?? '');
                                    $fl = (string) ($field['label'] ?? $fk);
                                    $ft = (string) ($field['type'] ?? 'text');
                                    $fv = (string) ($field['value'] ?? '');
                                    $fmin = isset($field['min_length']) ? (int) $field['min_length'] : 0;
                                    $fmax = isset($field['max_length']) ? (int) $field['max_length'] : 0;
                                    $fval = (string) ($field['validation'] ?? '');
                                    ?>
                                    <label><?php echo esc_html($fl); ?>
                                      <?php if ($ft === 'textarea') { ?>
                                        <textarea name="payload_fields[<?php echo esc_attr($fk); ?>]" rows="3"
                                          <?php if ($fmin > 0) { ?>data-min-length="<?php echo esc_attr((string) $fmin); ?>"<?php } ?>
                                          <?php if ($fmax > 0) { ?>data-max-length="<?php echo esc_attr((string) $fmax); ?>"<?php } ?>
                                          <?php if ($fval !== '') { ?>data-validation="<?php echo esc_attr($fval); ?>"<?php } ?>
                                        ><?php echo esc_textarea($fv); ?></textarea>
                                      <?php } else { ?>
                                        <input type="text" name="payload_fields[<?php echo esc_attr($fk); ?>]" value="<?php echo esc_attr($fv); ?>"
                                          <?php if ($fmin > 0) { ?>data-min-length="<?php echo esc_attr((string) $fmin); ?>"<?php } ?>
                                          <?php if ($fmax > 0) { ?>data-max-length="<?php echo esc_attr((string) $fmax); ?>"<?php } ?>
                                          <?php if ($fval !== '') { ?>data-validation="<?php echo esc_attr($fval); ?>"<?php } ?>
                                        >
                                      <?php } ?>
                                    </label>
                                <?php } ?>
                              </div>
                            </form>
                          <?php } ?>
                        </div>
                      </td>
                      <td class="seoworkerai-nowrap seoworkerai-muted" style="font-size:12px"><?php echo esc_html((string) ($row['received_at'] ?? '')); ?></td>
                      <td>
                        <div class="seoworkerai-action-btns">
                          <?php if (! $isApplied) { ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                              <?php wp_nonce_field('seoworkerai_apply_action'); ?>
                              <input type="hidden" name="action" value="seoworkerai_apply_action">
                              <input type="hidden" name="action_id" value="<?php echo esc_attr((string) $laravelId); ?>">
                              <button class="button button-small button-primary" type="submit">Apply</button>
                            </form>
                          <?php } ?>
                          <?php if ($isApplied) { ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                              <?php wp_nonce_field('seoworkerai_revert_action'); ?>
                              <input type="hidden" name="action" value="seoworkerai_revert_action">
                              <input type="hidden" name="action_id" value="<?php echo esc_attr((string) $laravelId); ?>">
                              <button class="button button-small seoworkerai-btn-danger" type="submit">Revert</button>
                            </form>
                          <?php } ?>
                        </div>
                      </td>
                    </tr>
                    <?php if ($hasLogs) { ?>
                      <tr id="progression-<?php echo esc_attr((string) $laravelId); ?>" class="seoworkerai-progression-row" style="display:none">
                        <td colspan="6" style="padding:0 0 0 32px;background:var(--line-2);border-top:none">
                          <div class="seoworkerai-prog-timeline">
                            <?php foreach (array_reverse($groupedChangeLogs[$laravelId]['events']) as $event) {
                                $evStatus = strtolower(trim((string) ($event['status'] ?? '')));
                                $evNote = (string) ($event['note'] ?? '');
                                $evAt = (string) ($event['created_at'] ?? '');
                                $showNote = $this->shouldRenderTimelineNote((string) ($event['event_type'] ?? ''), $evNote);
                                ?>
                                <div class="seoworkerai-prog-step">
                                  <div class="seoworkerai-prog-dot seoworkerai-prog-dot--<?php echo esc_attr($evStatus); ?>"></div>
                                  <div class="seoworkerai-prog-info">
                                    <?php echo wp_kses_post($this->renderStatusBadge($evStatus)); ?>
                                    <span class="seoworkerai-prog-ts"><?php echo esc_html($evAt); ?></span>
                                    <?php if ($showNote) { ?><div class="seoworkerai-prog-note"><?php echo esc_html($evNote); ?></div><?php } ?>
                                  </div>
                                </div>
                            <?php } ?>
                          </div>
                        </td>
                      </tr>
                    <?php } ?>
                <?php } ?>
              <?php } else { ?>
                <tr><td colspan="6" style="padding:32px;text-align:center;color:var(--ink-3)">No actions found. Adjust filters or wait for changes to arrive.</td></tr>
              <?php } ?>
              </tbody>
            </table>
          </div>
        </div>

        <?php
        $baseArgs = ['page' => 'seoworkerai-logs', 'per_page' => $perPage, 'q' => $search];
        foreach ($statusArr as $v) {
            $baseArgs['status'][] = $v;
        }
        foreach ($actionTypeArr as $v) {
            $baseArgs['action_type'][] = $v;
        }
        foreach ($targetTypeArr as $v) {
            $baseArgs['target_type'][] = $v;
        }
        foreach ($postIdArr as $v) {
            $baseArgs['post_id'][] = $v;
        }
        ?>
        <div class="seoworkerai-pagination">
          <div>Showing <?php echo esc_html((string) count($actions)); ?> of <?php echo esc_html((string) $totalActions); ?> (page <?php echo esc_html((string) $page); ?> / <?php echo esc_html((string) $totalPages); ?>)</div>
          <div class="seoworkerai-button-row">
            <a class="button <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo esc_url(add_query_arg(array_merge($baseArgs, ['paged' => max(1, $page - 1)]), admin_url('admin.php'))); ?>">← Previous</a>
            <a class="button <?php echo $page >= $totalPages ? 'disabled' : ''; ?>" href="<?php echo esc_url(add_query_arg(array_merge($baseArgs, ['paged' => min($totalPages, $page + 1)]), admin_url('admin.php'))); ?>">Next →</a>
          </div>
        </div>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:24px">
          <?php wp_nonce_field('seoworkerai_delete_logs'); ?>
          <input type="hidden" name="action" value="seoworkerai_delete_logs">
          <button type="submit" class="button seoworkerai-btn-danger" onclick="return confirm('Delete all execution logs? This cannot be undone.')">Delete All Logs</button>
        </form>
        <?php
        $this->renderAdminShellFooter();
    }

    // =========================================================================
    // renderActionItemsPage — UNCHANGED logic, updated shell calls
    // =========================================================================

    public function renderActionItemsPage(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        global $wpdb;
        $table = $wpdb->prefix.'seoworkerai_action_items';
        $actionsTable = $wpdb->prefix.'seoworkerai_actions';
        $siteId = (int) get_option('seoworkerai_site_id', 0);

        $statusArr = isset($_GET['status']) ? array_filter(array_map('sanitize_text_field', (array) $_GET['status'])) : [];
        $categoryArr = isset($_GET['category']) ? array_filter(array_map('sanitize_text_field', (array) $_GET['category'])) : [];
        $targetTypeArr = isset($_GET['target_type']) ? array_filter(array_map('sanitize_text_field', (array) $_GET['target_type'])) : [];
        $search = isset($_GET['q']) ? sanitize_text_field((string) $_GET['q']) : '';
        $page = max(1, (int) ($_GET['paged'] ?? 1));
        $perPage = min(100, max(10, (int) ($_GET['per_page'] ?? 10)));
        $offset = ($page - 1) * $perPage;
        $notice = isset($_GET['seoworkerai_notice']) ? sanitize_text_field((string) $_GET['seoworkerai_notice']) : '';

        $where = ['1=1'];
        $params = [];
        if ($siteId > 0) {
            $where[] = 'i.site_id = %d';
            $params[] = $siteId;
        } else {
            $where[] = '1=0';
        }
        if (! empty($statusArr)) {
            $where[] = 'i.status IN ('.implode(',', array_fill(0, count($statusArr), '%s')).')';
            $params = array_merge($params, $statusArr);
        }
        if (! empty($categoryArr)) {
            $where[] = 'i.category IN ('.implode(',', array_fill(0, count($categoryArr), '%s')).')';
            $params = array_merge($params, $categoryArr);
        }
        if (! empty($targetTypeArr)) {
            $where[] = 'a.target_type IN ('.implode(',', array_fill(0, count($targetTypeArr), '%s')).')';
            $params = array_merge($params, $targetTypeArr);
        }
        if ($search !== '') {
            $like = '%'.$wpdb->esc_like($search).'%';
            $where[] = '(i.title LIKE %s OR i.details LIKE %s OR i.recommended_value LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $whereSql = implode(' AND ', $where);

        $items = $wpdb->get_results($wpdb->prepare("SELECT i.*, a.target_type, a.target_id, a.target_url, a.action_type AS linked_action_type, a.status AS linked_action_status FROM {$table} i LEFT JOIN {$actionsTable} a ON a.laravel_action_id = i.laravel_action_id WHERE {$whereSql} ORDER BY i.updated_at DESC LIMIT %d OFFSET %d", ...array_merge($params, [$perPage, $offset])), ARRAY_A); // phpcs:ignore
        if (! is_array($items)) {
            $items = [];
        }
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} i LEFT JOIN {$actionsTable} a ON a.laravel_action_id = i.laravel_action_id WHERE {$whereSql}", ...$params)); // phpcs:ignore
        $totalPages = max(1, (int) ceil($total / $perPage));

        $categoryOptions = $siteId > 0 ? (array) $wpdb->get_col($wpdb->prepare("SELECT DISTINCT category FROM {$table} WHERE site_id = %d ORDER BY category ASC", $siteId)) : []; // phpcs:ignore
        $targetTypeOptions = $siteId > 0 ? (array) $wpdb->get_col($wpdb->prepare("SELECT DISTINCT a.target_type FROM {$table} i INNER JOIN {$actionsTable} a ON a.laravel_action_id = i.laravel_action_id WHERE i.site_id = %d AND a.target_type <> '' ORDER BY a.target_type ASC", $siteId)) : []; // phpcs:ignore
        $labelMapsJson = wp_json_encode(['status' => ['open' => 'Open', 'in_progress' => 'In Progress', 'resolved' => 'Resolved'], 'category' => array_combine($categoryOptions, $categoryOptions), 'target_type' => array_combine($targetTypeOptions, $targetTypeOptions)]);

        $this->renderAdminShellHeader('Action Items', 'seoworkerai-action-items', 'Track tasks that need your manual attention.');
        $this->renderNotice($notice);

        if ($siteId <= 0) {
            echo '<div class="notice notice-warning"><p>Site not yet registered. Action items will appear here once registration is complete.</p></div>';
            $this->renderAdminShellFooter();

            return;
        }

        // KPI row
        $totalOpen = count(array_filter((array) $items, fn ($i) => ($i['status'] ?? '') === 'open'));
        $totalInProgress = count(array_filter((array) $items, fn ($i) => ($i['status'] ?? '') === 'in_progress'));
        ?>
        <div class="seoworkerai-kpi-grid">
          <div class="seoworkerai-kpi-card"><span class="seoworkerai-kpi-label">Total</span><span class="seoworkerai-kpi-value"><?php echo esc_html((string) $total); ?></span></div>
          <div class="seoworkerai-kpi-card"><span class="seoworkerai-kpi-label">Open</span><span class="seoworkerai-kpi-value"><?php echo esc_html((string) $totalOpen); ?></span></div>
          <div class="seoworkerai-kpi-card"><span class="seoworkerai-kpi-label">In Progress</span><span class="seoworkerai-kpi-value"><?php echo esc_html((string) $totalInProgress); ?></span></div>
          <div class="seoworkerai-kpi-card"><span class="seoworkerai-kpi-label">On This Page</span><span class="seoworkerai-kpi-value"><?php echo esc_html((string) count($items)); ?></span></div>
        </div>

        <!-- Filter bar -->
        <div class="seoworkerai-chip-filter-bar" id="seoworkerai-filter-bar" data-label-maps="<?php echo esc_attr((string) $labelMapsJson); ?>">
          <form method="get" class="seoworkerai-filter-form" id="seoworkerai-filter-form">
            <input type="hidden" name="page" value="seoworkerai-action-items">
            <input type="hidden" name="per_page" value="<?php echo esc_attr((string) $perPage); ?>">
            <div class="seoworkerai-active-chips" id="seoworkerai-active-chips"></div>
            <div class="seoworkerai-filter-dropdowns">
              <?php
              $filterDefs = [
                  ['key' => 'status',      'label' => 'Status',      'options' => ['open' => 'Open', 'in_progress' => 'In Progress', 'resolved' => 'Resolved']],
                  ['key' => 'category',    'label' => 'Category',    'options' => array_combine($categoryOptions, $categoryOptions)],
                  ['key' => 'target_type', 'label' => 'Target Type', 'options' => array_combine($targetTypeOptions, $targetTypeOptions)],
              ];
        foreach ($filterDefs as $fd) {
            if (empty($fd['options'])) {
                continue;
            }
            $activeVals = match ($fd['key']) {
                'status' => $statusArr, 'category' => $categoryArr, 'target_type' => $targetTypeArr, default => []
            };
            ?>
                  <div class="seoworkerai-filter-dropdown" data-filter-key="<?php echo esc_attr($fd['key']); ?>">
                    <button type="button" class="seoworkerai-filter-btn <?php echo ! empty($activeVals) ? 'has-active' : ''; ?>">
                      <?php echo esc_html($fd['label']); ?>
                      <?php if (! empty($activeVals)) {
                          echo '<span class="seoworkerai-filter-count">'.count($activeVals).'</span>';
                      } ?>
                      <span class="seoworkerai-filter-chevron">▾</span>
                    </button>
                    <div class="seoworkerai-filter-panel" style="display:none">
                      <div class="seoworkerai-filter-panel-inner">
                        <?php foreach ($fd['options'] as $val => $label) { ?>
                          <label class="seoworkerai-filter-option">
                            <input type="checkbox" name="<?php echo esc_attr($fd['key']); ?>[]"
                              value="<?php echo esc_attr((string) $val); ?>"
                              <?php checked(in_array((string) $val, $activeVals, true)); ?>>
                            <?php echo esc_html((string) $label); ?>
                          </label>
                        <?php } ?>
                      </div>
                      <div class="seoworkerai-filter-panel-footer">
                        <button type="button" class="seoworkerai-filter-clear-one button-link"
                          data-filter-key="<?php echo esc_attr($fd['key']); ?>">Clear</button>
                        <button type="button" class="button button-small button-primary seoworkerai-filter-apply">Apply</button>
                      </div>
                    </div>
                  </div>
              <?php } ?>
              <div style="display:flex;gap:6px;align-items:center;margin-left:auto">
                <input type="text" name="q" value="<?php echo esc_attr($search); ?>" placeholder="Search items…"
                  style="height:30px;padding:0 10px;border:1px solid var(--line);border-radius:var(--radius-sm);font-size:13px;width:200px;font-family:var(--font)">
                <button class="button button-primary" type="submit">Search</button>
                <?php if (! empty($statusArr) || ! empty($categoryArr) || ! empty($targetTypeArr) || $search !== '') { ?>
                  <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=seoworkerai-action-items')); ?>">Reset</a>
                <?php } ?>
              </div>
            </div>
            <div id="seoworkerai-filter-hidden-inputs" class="seoworkerai-filter-hidden-inputs"></div>
          </form>
        </div>

        <div class="seoworkerai-card" style="padding:0;overflow:hidden">
          <div class="seoworkerai-table-wrap">
            <table class="wp-list-table widefat seoworkerai-items-table">
              <thead>
                <tr>
                  <th style="min-width:260px">Title</th>
                  <th style="min-width:90px">Category</th>
                  <th style="min-width:90px">Status</th>
                  <th style="min-width:220px">Details / Recommendation</th>
                  <th style="min-width:140px">Target</th>
                  <th style="min-width:110px">Created</th>
                  <th style="min-width:100px">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php if (! empty($items)) {
                  foreach ($items as $item) {
                      $itemId = (int) ($item['id'] ?? 0);
                      $itemStatus = (string) ($item['status'] ?? 'open');
                      $targetUrl = (string) ($item['target_url'] ?? '');
                      $targetType = (string) ($item['target_type'] ?? '');
                      $linkedActionType = (string) ($item['linked_action_type'] ?? '');
                      ?>
                      <tr>
                        <td>
                          <strong><?php echo esc_html((string) ($item['title'] ?? '')); ?></strong>
                          <?php if ($linkedActionType !== '') { ?>
                            <div style="margin-top:3px"><code style="font-size:11px"><?php echo esc_html($linkedActionType); ?></code></div>
                          <?php } ?>
                        </td>
                        <td>
                          <span class="seoworkerai-badge seoworkerai-badge--gray"><?php echo esc_html((string) ($item['category'] ?? '—')); ?></span>
                        </td>
                        <td><?php echo wp_kses_post($this->renderStatusBadge($itemStatus)); ?></td>
                        <td>
                          <?php if ((string) ($item['details'] ?? '') !== '') { ?>
                            <div style="font-size:12px;color:var(--ink-2);margin-bottom:4px"><?php echo esc_html((string) $item['details']); ?></div>
                          <?php } ?>
                          <?php if ((string) ($item['recommended_value'] ?? '') !== '') { ?>
                            <div style="font-size:12px;color:var(--ink-3)">
                              <span style="font-weight:500">Recommended:</span> <?php echo esc_html((string) $item['recommended_value']); ?>
                            </div>
                          <?php } ?>
                        </td>
                        <td>
                          <?php if ($targetUrl !== '') { ?>
                            <a href="<?php echo esc_url($targetUrl); ?>" target="_blank" rel="noopener noreferrer"
                               class="seoworkerai-truncate" style="display:block;max-width:180px;font-size:12px;color:var(--blue)">
                              <?php echo esc_html($targetUrl); ?>
                            </a>
                          <?php } elseif ($targetType !== '') { ?>
                            <span class="seoworkerai-muted"><?php echo esc_html($targetType); ?></span>
                          <?php } else { ?>
                            <span class="seoworkerai-muted">—</span>
                          <?php } ?>
                        </td>
                        <td class="seoworkerai-muted" style="font-size:12px"><?php echo esc_html(substr((string) ($item['created_at'] ?? ''), 0, 16)); ?></td>
                        <td>
                          <div class="seoworkerai-action-btns">
                            <?php if ($itemStatus !== 'resolved') { ?>
                              <?php if ($itemStatus === 'open') { ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                  <?php wp_nonce_field('seoworkerai_update_action_item'); ?>
                                  <input type="hidden" name="action" value="seoworkerai_update_action_item">
                                  <input type="hidden" name="item_id" value="<?php echo esc_attr((string) $itemId); ?>">
                                  <input type="hidden" name="status" value="in_progress">
                                  <button type="submit" class="button button-small">Start</button>
                                </form>
                              <?php } ?>
                              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php wp_nonce_field('seoworkerai_update_action_item'); ?>
                                <input type="hidden" name="action" value="seoworkerai_update_action_item">
                                <input type="hidden" name="item_id" value="<?php echo esc_attr((string) $itemId); ?>">
                                <input type="hidden" name="status" value="resolved">
                                <button type="submit" class="button button-small button-primary">Resolve</button>
                              </form>
                            <?php } else { ?>
                              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php wp_nonce_field('seoworkerai_update_action_item'); ?>
                                <input type="hidden" name="action" value="seoworkerai_update_action_item">
                                <input type="hidden" name="item_id" value="<?php echo esc_attr((string) $itemId); ?>">
                                <input type="hidden" name="status" value="open">
                                <button type="submit" class="button button-small">Reopen</button>
                              </form>
                            <?php } ?>
                          </div>
                        </td>
                      </tr>
                  <?php }
                  } else { ?>
                <tr><td colspan="7" style="padding:48px 24px;text-align:center;color:var(--ink-3)">
                  No action items found<?php echo (! empty($statusArr) || ! empty($categoryArr) || $search !== '') ? ' matching your filters' : ''; ?>.
                </td></tr>
              <?php } ?>
              </tbody>
            </table>
          </div>
        </div>

        <?php
        $baseArgs = ['page' => 'seoworkerai-action-items', 'per_page' => $perPage, 'q' => $search];
        foreach ($statusArr as $v) {
            $baseArgs['status'][] = $v;
        }
        foreach ($categoryArr as $v) {
            $baseArgs['category'][] = $v;
        }
        foreach ($targetTypeArr as $v) {
            $baseArgs['target_type'][] = $v;
        }
        ?>
        <div class="seoworkerai-pagination">
          <div>Showing <?php echo esc_html((string) count($items)); ?> of <?php echo esc_html((string) $total); ?> (page <?php echo esc_html((string) $page); ?> / <?php echo esc_html((string) $totalPages); ?>)</div>
          <div class="seoworkerai-button-row">
            <a class="button <?php echo $page <= 1 ? 'disabled' : ''; ?>"
               href="<?php echo esc_url(add_query_arg([...$baseArgs, 'paged' => max(1, $page - 1)], admin_url('admin.php'))); ?>">← Previous</a>
            <a class="button <?php echo $page >= $totalPages ? 'disabled' : ''; ?>"
               href="<?php echo esc_url(add_query_arg([...$baseArgs, 'paged' => min($totalPages, $page + 1)], admin_url('admin.php'))); ?>">Next →</a>
          </div>
        </div>
        <?php
        $this->renderAdminShellFooter();
    }

    // =========================================================================
    // renderBriefsPage, renderSchedulesPage, renderLocalErrorsPage,
    // renderOauthCallbackPage
    // — UNCHANGED logic. Only change: remove outer wrap div, replace
    //   $this->renderAdminShellHeader(...) call with the new one, and
    //   add $this->renderAdminShellFooter() at the end.
    // =========================================================================

    public function renderBriefsPage(): void
    {
        if (! current_user_can('edit_posts')) {
            wp_die('Unauthorized');
        }

        global $wpdb;
        $table = $wpdb->prefix.'seoworkerai_briefs';
        $notice = isset($_GET['seoworkerai_notice']) ? sanitize_text_field((string) $_GET['seoworkerai_notice']) : '';

        $statusArr = isset($_GET['article_status']) ? array_filter(array_map('sanitize_text_field', (array) $_GET['article_status'])) : [];
        $assignmentArr = isset($_GET['assignment_status']) ? array_filter(array_map('sanitize_text_field', (array) $_GET['assignment_status'])) : [];
        $keywordTypeArr = isset($_GET['keyword_type']) ? array_filter(array_map('sanitize_text_field', (array) $_GET['keyword_type'])) : [];
        $search = isset($_GET['q']) ? sanitize_text_field((string) $_GET['q']) : '';
        $page = max(1, (int) ($_GET['paged'] ?? 1));
        $perPage = min(100, max(10, (int) ($_GET['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        $where = ['1=1'];
        $params = [];

        if (! empty($statusArr)) {
            $where[] = 'article_status IN ('.implode(',', array_fill(0, count($statusArr), '%s')).')';
            $params = [...$params, ...$statusArr];
        }
        if (! empty($assignmentArr)) {
            $where[] = 'assignment_status IN ('.implode(',', array_fill(0, count($assignmentArr), '%s')).')';
            $params = [...$params, ...$assignmentArr];
        }
        if (! empty($keywordTypeArr)) {
            $where[] = 'keyword_type IN ('.implode(',', array_fill(0, count($keywordTypeArr), '%s')).')';
            $params = [...$params, ...$keywordTypeArr];
        }
        if ($search !== '') {
            $like = '%'.$wpdb->esc_like($search).'%';
            $where[] = '(brief_title LIKE %s OR focus_keyword LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        $whereSql = implode(' AND ', $where);
        $queryParams = [...$params, $perPage, $offset];

        $briefs = $wpdb->get_results( // phpcs:ignore
            $wpdb->prepare("SELECT * FROM {$table} WHERE {$whereSql} ORDER BY updated_at DESC LIMIT %d OFFSET %d", ...$queryParams),
            ARRAY_A
        );
        if (! is_array($briefs)) {
            $briefs = [];
        }
        $total = (int) $wpdb->get_var( // phpcs:ignore
            $params !== []
                ? $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$whereSql}", ...$params)
                : "SELECT COUNT(*) FROM {$table} WHERE {$whereSql}"
        );
        $totalPages = max(1, (int) ceil($total / $perPage));

        $articleStatusOptions = (array) $wpdb->get_col("SELECT DISTINCT article_status FROM {$table} WHERE article_status != '' ORDER BY article_status ASC"); // phpcs:ignore
        $assignmentStatusOptions = (array) $wpdb->get_col("SELECT DISTINCT assignment_status FROM {$table} ORDER BY assignment_status ASC"); // phpcs:ignore
        $keywordTypeOptions = (array) $wpdb->get_col("SELECT DISTINCT keyword_type FROM {$table} WHERE keyword_type IS NOT NULL ORDER BY keyword_type ASC"); // phpcs:ignore

        $wpUsers = get_users(['role__in' => ['administrator', 'editor', 'author', 'contributor'], 'fields' => ['ID', 'display_name']]);
        $userMap = [];
        foreach ($wpUsers as $u) {
            $userMap[(int) $u->ID] = (string) $u->display_name;
        }

        $labelMapsJson = wp_json_encode([
            'article_status' => array_combine($articleStatusOptions, $articleStatusOptions),
            'assignment_status' => array_combine($assignmentStatusOptions, $assignmentStatusOptions),
            'keyword_type' => array_combine($keywordTypeOptions, $keywordTypeOptions),
        ]);

        $this->renderAdminShellHeader('Content Briefs', 'seoworkerai-briefs', 'AI-generated content briefs ready to assign to authors.');
        $this->renderNotice($notice);
        ?>
        <div class="seoworkerai-kpi-grid">
          <div class="seoworkerai-kpi-card"><span class="seoworkerai-kpi-label">Total Briefs</span><span class="seoworkerai-kpi-value"><?php echo esc_html((string) $total); ?></span></div>
          <div class="seoworkerai-kpi-card"><span class="seoworkerai-kpi-label">On This Page</span><span class="seoworkerai-kpi-value"><?php echo esc_html((string) count($briefs)); ?></span></div>
          <div class="seoworkerai-kpi-card"><span class="seoworkerai-kpi-label">Unassigned</span><span class="seoworkerai-kpi-value"><?php echo esc_html((string) ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE assignment_status = 'unassigned'"))); // phpcs:ignore?></span></div>
          <div class="seoworkerai-kpi-card"><span class="seoworkerai-kpi-label">Linked to Post</span><span class="seoworkerai-kpi-value"><?php echo esc_html((string) ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE linked_wp_post_id IS NOT NULL"))); // phpcs:ignore?></span></div>
        </div>

        <!-- Filter bar -->
        <div class="seoworkerai-chip-filter-bar" id="seoworkerai-filter-bar" data-label-maps="<?php echo esc_attr((string) $labelMapsJson); ?>">
          <form method="get" class="seoworkerai-filter-form" id="seoworkerai-filter-form">
            <input type="hidden" name="page" value="seoworkerai-briefs">
            <input type="hidden" name="per_page" value="<?php echo esc_attr((string) $perPage); ?>">
            <div class="seoworkerai-active-chips" id="seoworkerai-active-chips"></div>
            <div class="seoworkerai-filter-dropdowns">
              <?php
              $briefFilterDefs = [
                  ['key' => 'article_status',    'label' => 'Article Status',    'options' => array_combine($articleStatusOptions, $articleStatusOptions), 'active' => $statusArr],
                  ['key' => 'assignment_status', 'label' => 'Assignment',        'options' => array_combine($assignmentStatusOptions, $assignmentStatusOptions), 'active' => $assignmentArr],
                  ['key' => 'keyword_type',      'label' => 'Keyword Type',      'options' => array_combine($keywordTypeOptions, $keywordTypeOptions), 'active' => $keywordTypeArr],
              ];
        foreach ($briefFilterDefs as $fd) {
            if (empty($fd['options'])) {
                continue;
            }
            ?>
                  <div class="seoworkerai-filter-dropdown" data-filter-key="<?php echo esc_attr($fd['key']); ?>">
                    <button type="button" class="seoworkerai-filter-btn <?php echo ! empty($fd['active']) ? 'has-active' : ''; ?>">
                      <?php echo esc_html($fd['label']); ?>
                      <?php if (! empty($fd['active'])) {
                          echo '<span class="seoworkerai-filter-count">'.count($fd['active']).'</span>';
                      } ?>
                      <span class="seoworkerai-filter-chevron">▾</span>
                    </button>
                    <div class="seoworkerai-filter-panel" style="display:none">
                      <div class="seoworkerai-filter-panel-inner">
                        <?php foreach ($fd['options'] as $val => $label) { ?>
                          <label class="seoworkerai-filter-option">
                            <input type="checkbox" name="<?php echo esc_attr($fd['key']); ?>[]"
                              value="<?php echo esc_attr((string) $val); ?>"
                              <?php checked(in_array((string) $val, $fd['active'], true)); ?>>
                            <?php echo esc_html((string) $label); ?>
                          </label>
                        <?php } ?>
                      </div>
                      <div class="seoworkerai-filter-panel-footer">
                        <button type="button" class="seoworkerai-filter-clear-one button-link"
                          data-filter-key="<?php echo esc_attr($fd['key']); ?>">Clear</button>
                        <button type="button" class="button button-small button-primary seoworkerai-filter-apply">Apply</button>
                      </div>
                    </div>
                  </div>
              <?php } ?>
              <div style="display:flex;gap:6px;align-items:center;margin-left:auto">
                <input type="text" name="q" value="<?php echo esc_attr($search); ?>" placeholder="Search keyword or title…"
                  style="height:30px;padding:0 10px;border:1px solid var(--line);border-radius:var(--radius-sm);font-size:13px;width:220px;font-family:var(--font)">
                <button class="button button-primary" type="submit">Search</button>
                <?php if (! empty($statusArr) || ! empty($assignmentArr) || ! empty($keywordTypeArr) || $search !== '') { ?>
                  <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=seoworkerai-briefs')); ?>">Reset</a>
                <?php } ?>
              </div>
            </div>
            <div id="seoworkerai-filter-hidden-inputs" class="seoworkerai-filter-hidden-inputs"></div>
          </form>
        </div>

        <div class="seoworkerai-card" style="padding:0;overflow:hidden">
          <div class="seoworkerai-table-wrap">
            <table class="wp-list-table widefat" style="min-width:960px">
              <thead>
                <tr>
                  <th style="min-width:240px">Brief Title / Keyword</th>
                  <th style="min-width:100px">Keyword Type</th>
                  <th style="min-width:80px">Volume</th>
                  <th style="min-width:60px">KD</th>
                  <th style="min-width:100px">Article Status</th>
                  <th style="min-width:110px">Assignment</th>
                  <th style="min-width:200px">Linked Post</th>
                  <th style="min-width:100px">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php if (! empty($briefs)) {
                  foreach ($briefs as $brief) {
                      $briefId = (int) ($brief['id'] ?? 0);
                      $laravelId = (int) ($brief['laravel_content_brief_id'] ?? 0);
                      $assignedUserId = (int) ($brief['assigned_wp_user_id'] ?? 0);
                      $linkedPostId = (int) ($brief['linked_wp_post_id'] ?? 0);
                      $assignedName = $assignedUserId > 0 ? ($userMap[$assignedUserId] ?? "User #{$assignedUserId}") : '';
                      ?>
                      <tr>
                        <td>
                          <strong><?php echo esc_html((string) ($brief['brief_title'] ?? '—')); ?></strong>
                          <?php if ((string) ($brief['focus_keyword'] ?? '') !== '') { ?>
                            <div style="margin-top:3px;font-size:12px;color:var(--ink-3)">
                              <?php echo esc_html((string) $brief['focus_keyword']); ?>
                              <?php if ((string) ($brief['search_intent'] ?? '') !== '') { ?>
                                <span style="margin-left:4px" class="seoworkerai-badge seoworkerai-badge--gray"><?php echo esc_html((string) $brief['search_intent']); ?></span>
                              <?php } ?>
                            </div>
                          <?php } ?>
                          <?php if ((string) ($brief['strategy_template_name'] ?? '') !== '') { ?>
                            <div style="font-size:11px;color:var(--ink-4);margin-top:2px"><?php echo esc_html((string) $brief['strategy_template_name']); ?></div>
                          <?php } ?>
                        </td>
                        <td>
                          <?php if ((string) ($brief['keyword_type'] ?? '') !== '') { ?>
                            <span class="seoworkerai-badge seoworkerai-badge--gray"><?php echo esc_html((string) $brief['keyword_type']); ?></span>
                          <?php } else { ?>
                            <span class="seoworkerai-muted">—</span>
                          <?php } ?>
                        </td>
                        <td class="seoworkerai-muted" style="font-size:13px">
                          <?php echo $brief['search_volume'] !== null ? esc_html(number_format((int) $brief['search_volume'])) : '—'; ?>
                        </td>
                        <td class="seoworkerai-muted" style="font-size:13px">
                          <?php echo $brief['keyword_difficulty'] !== null ? esc_html((string) $brief['keyword_difficulty']) : '—'; ?>
                        </td>
                        <td><?php echo wp_kses_post($this->renderStatusBadge((string) ($brief['article_status'] ?? 'draft'))); ?></td>
                        <td>
                          <?php if ($assignedName !== '') { ?>
                            <div style="font-size:13px"><?php echo esc_html($assignedName); ?></div>
                          <?php } ?>
                          <div style="margin-top:2px"><?php echo wp_kses_post($this->renderStatusBadge((string) ($brief['assignment_status'] ?? 'unassigned'))); ?></div>
                        </td>
                        <td>
                          <?php if ($linkedPostId > 0) { ?>
                            <div style="font-size:12px">
                              <a href="<?php echo esc_url((string) ($brief['linked_wp_post_url'] ?? get_permalink($linkedPostId))); ?>"
                                 target="_blank" rel="noopener noreferrer" style="color:var(--blue)">
                                <?php echo esc_html((string) ($brief['linked_wp_post_title'] ?? "Post #{$linkedPostId}")); ?>
                              </a>
                            </div>
                            <div style="font-size:11px;color:var(--ink-4);margin-top:2px"><?php echo esc_html((string) ($brief['linked_wp_post_type'] ?? '')); ?></div>
                          <?php } else { ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:6px;align-items:center">
                              <?php wp_nonce_field('seoworkerai_link_brief'); ?>
                              <input type="hidden" name="action" value="seoworkerai_link_brief">
                              <input type="hidden" name="brief_id" value="<?php echo esc_attr((string) $briefId); ?>">
                              <input type="number" name="post_id" placeholder="Post ID" min="1"
                                style="width:80px;height:26px;padding:0 6px;border:1px solid var(--line);border-radius:var(--radius-sm);font-size:12px">
                              <button type="submit" class="button button-small">Link</button>
                            </form>
                          <?php } ?>
                        </td>
                        <td>
                          <?php if ($linkedPostId > 0) { ?>
                            <a class="button button-small"
                               href="<?php echo esc_url(get_edit_post_link($linkedPostId) ?: ''); ?>">Edit post</a>
                          <?php } else { ?>
                            <a class="button button-small"
                               href="<?php echo esc_url(admin_url('post-new.php')); ?>">New post</a>
                          <?php } ?>
                        </td>
                      </tr>
                  <?php }
                  } else { ?>
                <tr><td colspan="8" style="padding:48px 24px;text-align:center;color:var(--ink-3)">
                  No content briefs found<?php echo (! empty($statusArr) || ! empty($assignmentArr) || $search !== '') ? ' matching your filters' : '. Briefs will appear here after a sync.'; ?>.
                </td></tr>
              <?php } ?>
              </tbody>
            </table>
          </div>
        </div>

        <?php
        $baseArgs = ['page' => 'seoworkerai-briefs', 'per_page' => $perPage, 'q' => $search];
        foreach ($statusArr as $v) {
            $baseArgs['article_status'][] = $v;
        }
        foreach ($assignmentArr as $v) {
            $baseArgs['assignment_status'][] = $v;
        }
        foreach ($keywordTypeArr as $v) {
            $baseArgs['keyword_type'][] = $v;
        }
        ?>
        <div class="seoworkerai-pagination">
          <div>Showing <?php echo esc_html((string) count($briefs)); ?> of <?php echo esc_html((string) $total); ?> (page <?php echo esc_html((string) $page); ?> / <?php echo esc_html((string) $totalPages); ?>)</div>
          <div class="seoworkerai-button-row">
            <a class="button <?php echo $page <= 1 ? 'disabled' : ''; ?>"
               href="<?php echo esc_url(add_query_arg([...$baseArgs, 'paged' => max(1, $page - 1)], admin_url('admin.php'))); ?>">← Previous</a>
            <a class="button <?php echo $page >= $totalPages ? 'disabled' : ''; ?>"
               href="<?php echo esc_url(add_query_arg([...$baseArgs, 'paged' => min($totalPages, $page + 1)], admin_url('admin.php'))); ?>">Next →</a>
          </div>
        </div>
        <?php
        $this->renderAdminShellFooter();
    }

    public function renderSchedulesPage(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        // $this->renderAdminShellHeader('Schedules', 'seoworkerai-schedules', '...');
        // ... page HTML ...
        // $this->renderAdminShellFooter();
    }

    public function renderLocalErrorsPage(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        // $this->renderAdminShellHeader('Debug Logs', 'seoworkerai-local-errors', '...');
        // ... page HTML ...
        // $this->renderAdminShellFooter();
    }

    public function renderOauthCallbackPage(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        // $this->renderAdminShellHeader('Google Connection', 'seoworkerai', '');
        // ... page HTML ...
        // $this->renderAdminShellFooter();
    }

    // =========================================================================
    // Private helpers — ALL UNCHANGED from original
    // =========================================================================

    private function renderNotice(string $notice): void
    {
        $map = [
            'register_ok' => ['success', 'Site registration updated successfully.'],
            'register_missing_base_url' => ['warning', 'No endpoint configured. Check plugin settings.'],
            'register_failed' => ['error',   'Site registration failed. Check debug logs.'],
            'health_ok' => ['success', 'Health check passed — connection is working.'],
            'health_failed' => ['warning', 'Health check failed — check your connection settings.'],
            'oauth_init_failed' => ['error',   'Failed to start Google authorization. Ensure site is registered.'],
            'domain_rating_required' => ['warning', 'Set Domain Rating in Settings before connecting Google.'],
            'oauth_revoke_ok' => ['success', 'Google account disconnected.'],
            'oauth_revoke_failed' => ['error',   'Disconnect failed. Check debug logs.'],
            'rotate_ok' => ['success', 'API token rotated successfully.'],
            'rotate_failed' => ['error',   'Token rotation failed. Check debug logs.'],
            'profile_ok' => ['success', 'Site profile synced.'],
            'profile_failed' => ['error',   'Profile sync failed.'],
            'strategy_settings_ok' => ['success', 'Strategy settings synced.'],
            'strategy_settings_failed' => ['error',   'Failed to sync strategy settings.'],
            'task_update_ok' => ['success', 'Task configuration saved.'],
            'task_update_failed' => ['error',   'Task configuration update failed.'],
            'task_schedule_ok' => ['success', 'Task scheduled successfully.'],
            'task_schedule_failed' => ['error',   'Task scheduling failed.'],
            'brief_link_ok' => ['success', 'Content brief linked to post.'],
            'brief_link_failed' => ['error',   'Failed to link brief. Check post ID.'],
            'logs_delete_ok' => ['success', 'Execution logs cleared.'],
            'logs_delete_failed' => ['error',   'Failed to delete logs.'],
            'local_errors_delete_ok' => ['success', 'Debug log entries cleared.'],
            'local_errors_delete_failed' => ['error',   'Failed to clear debug logs.'],
            'action_apply_requested' => ['success', 'Change queued for execution.'],
            'action_revert_ok' => ['success', 'Change reverted successfully.'],
            'action_revert_failed' => ['error',   'Revert failed — the change may not be reversible.'],
            'action_edit_ok' => ['success', 'Change updated.'],
            'action_edit_ok_reapply' => ['success', 'Change updated and re-queued for application.'],
            'action_edit_validation_failed' => ['error', 'Validation failed. Please fix field values and try again.'],
            'action_edit_failed' => ['error',   'Failed to update change.'],
            'action_item_updated' => ['success', 'Action item updated.'],
            'audit_notice_dismissed' => ['success', 'Initial audit notice dismissed for 3 days.'],
        ];
        if (! isset($map[$notice])) {
            return;
        }
        [$type, $message] = $map[$notice];
        echo '<div class="notice notice-'.esc_attr($type).'"><p>'.esc_html($message).'</p></div>';
    }

    private function renderStatusBadge(string $status): string
    {
        $normalized = sanitize_html_class(str_replace('_', '-', strtolower(trim($status))));
        $label = $this->humanizeLabel($status);

        return sprintf('<span class="seoworkerai-badge seoworkerai-status-%s">%s</span>', esc_attr($normalized), esc_html($label));
    }

    private function shouldRenderTimelineNote(string $eventType, string $note): bool
    {
        if (trim($note) === '') {
            return false;
        }
        $systemNotes = ['action received from laravel.', 'action queued for execution.', 'action execution started.', 'action awaiting manual review.'];

        return ! in_array(strtolower(trim($note)), $systemNotes, true);
    }

    private function resolveActionRedirectPage(): string
    {
        $returnPage = isset($_POST['return_page']) ? sanitize_text_field((string) wp_unslash($_POST['return_page'])) : '';

        return in_array($returnPage, ['seoworkerai-logs', 'seoworkerai-action-items'], true) ? $returnPage : 'seoworkerai-logs';
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $payload */
    private function buildActionDisplayTitle(array $row, array $payload): string
    {
        $title = trim((string) ($payload['title'] ?? ''));
        if ($title !== '') {
            return $title;
        }
        $targetType = (string) ($row['target_type'] ?? '');
        $targetId = (string) ($row['target_id'] ?? '');
        $targetUrl = (string) ($row['target_url'] ?? '');
        $targetLabel = '';
        if ($targetType === 'post' && ctype_digit($targetId)) {
            $pt = get_the_title((int) $targetId);
            if (is_string($pt) && trim($pt) !== '') {
                $targetLabel = trim($pt);
            }
        }
        if ($targetLabel === '' && $targetUrl !== '') {
            $targetLabel = parse_url($targetUrl, PHP_URL_PATH) ?: $targetUrl;
        }
        if ($targetLabel === '' && $targetType !== '' && $targetId !== '') {
            $targetLabel = "{$targetType}:{$targetId}";
        }
        $actionLabel = $this->humanizeLabel((string) ($row['action_type'] ?? 'action'));

        return $targetLabel !== '' ? "{$targetLabel} — {$actionLabel}" : $actionLabel;
    }

    /** @param array<string,mixed> $payload @return array<int,array{key:string,label:string,type:string,value:string}> */
    private function buildEditableFields(string $actionType, array $payload): array
    {
        return match ($actionType) {
            'add-meta-description','update-meta-description' => [['key' => 'meta_description', 'label' => 'Meta Description', 'type' => 'textarea', 'value' => (string) ($payload['meta_description'] ?? $payload['recommended_meta_description'] ?? ''), 'min_length' => 70, 'max_length' => 160]],
            'add-alt-text' => [['key' => 'alt_text',  'label' => 'Image Alt Text', 'type' => 'text',     'value' => (string) ($payload['alt_text'] ?? $payload['suggested_alt'] ?? '')]],
            'update-title' => [['key' => 'seo_title', 'label' => 'SEO Title',      'type' => 'text',     'value' => (string) ($payload['seo_title'] ?? $payload['title'] ?? '')]],
            'set-social-tags' => [
                ['key' => 'social_tags_og_title',       'label' => 'OG Title',       'type' => 'text',     'value' => (string) ($payload['social_tags']['og']['title'] ?? '')],
                ['key' => 'social_tags_og_description', 'label' => 'OG Description', 'type' => 'textarea', 'value' => (string) ($payload['social_tags']['og']['description'] ?? ''), 'max_length' => 200],
                ['key' => 'social_tags_twitter_site',   'label' => 'Twitter/X Handle', 'type' => 'text',     'value' => (string) ($payload['social_tags']['twitter']['site'] ?? ''), 'validation' => 'twitter_handle', 'max_length' => 16],
            ],
            default => [],
        };
    }

    /** @param array<string,mixed> $payload @return array<int,array{label:string,value:string}> */
    private function buildReadOnlyFields(string $actionType, array $payload, array $before, array $after): array
    {
        if (in_array($actionType, ['add-meta-description', 'update-meta-description'], true)) {
            return [['label' => 'Meta Description', 'value' => (string) ($payload['meta_description'] ?? $payload['recommended_meta_description'] ?? '')]];
        }
        if ($actionType === 'add-alt-text') {
            return [['label' => 'Alt Text',    'value' => (string) ($payload['alt_text'] ?? $payload['suggested_alt'] ?? '')]];
        }
        if (in_array($actionType, ['add-schema', 'add-schema-markup'], true)) {
            return [['label' => 'Schema Type', 'value' => (string) ($payload['schema_type'] ?? 'Article')]];
        }
        if ($actionType === 'set-social-tags') {
            return [['label' => 'OG Title', 'value' => (string) ($payload['social_tags']['og']['title'] ?? '')], ['label' => 'Twitter/X', 'value' => (string) ($payload['social_tags']['twitter']['site'] ?? '')]];
        }
        if ($actionType === 'set-post-dates') {
            return [['label' => 'Published At', 'value' => (string) ($payload['published_at'] ?? '')], ['label' => 'Modified At', 'value' => (string) ($payload['modified_at'] ?? '')]];
        }
        if ($actionType === 'update-title') {
            return [['label' => 'SEO Title', 'value' => (string) ($payload['seo_title'] ?? $payload['title'] ?? '')]];
        }
        if ($after !== []) {
            $first = array_key_first($after);
            if (is_string($first)) {
                $v = $after[$first] ?? '';

                return [['label' => $this->humanizeLabel($first), 'value' => is_scalar($v) ? (string) $v : 'Updated']];
            }
        }

        return [];
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $fields @return array<string,mixed> */
    private function applyPayloadFieldEdits(array $payload, array $fields): array
    {
        foreach ($fields as $key => $value) {
            $k = sanitize_text_field((string) $key);
            $v = is_scalar($value) ? sanitize_textarea_field((string) $value) : '';
            if ($k === 'meta_description') {
                $payload['meta_description'] = $v;
                $payload['recommended_meta_description'] = $v;

                continue;
            }
            if ($k === 'alt_text') {
                $payload['alt_text'] = $v;
                $payload['suggested_alt'] = $v;

                continue;
            }
            if ($k === 'seo_title') {
                $payload['seo_title'] = $v;
                $payload['title'] = $v;

                continue;
            }
            if ($k === 'social_tags_og_title') {
                $payload['social_tags']['og']['title'] = $v;

                continue;
            }
            if ($k === 'social_tags_og_description') {
                $payload['social_tags']['og']['description'] = $v;

                continue;
            }
            if ($k === 'social_tags_twitter_site') {
                $payload['social_tags']['twitter']['site'] = $v;

                continue;
            }
            $payload[$k] = $v;
        }

        return $payload;
    }

    /** @param array<string,mixed> $payload */
    private function validateEditedPayload(string $actionType, array $payload): ?string
    {
        if (in_array($actionType, ['add-meta-description', 'update-meta-description'], true)) {
            $meta = trim((string) ($payload['meta_description'] ?? $payload['recommended_meta_description'] ?? ''));
            $len = function_exists('mb_strlen') ? mb_strlen($meta) : strlen($meta);
            if ($len < 70 || $len > 160) {
                return 'Meta description must be between 70 and 160 characters.';
            }
        }
        if ($actionType === 'set-social-tags') {
            $ogDesc = trim((string) ($payload['social_tags']['og']['description'] ?? ''));
            if ($ogDesc !== '' && (function_exists('mb_strlen') ? mb_strlen($ogDesc) : strlen($ogDesc)) > 200) {
                return 'OG description must be 200 characters or fewer.';
            }
            $twitterSite = trim((string) ($payload['social_tags']['twitter']['site'] ?? ''));
            if ($twitterSite !== '' && ! preg_match('/^@?[A-Za-z0-9_]{1,15}$/', $twitterSite)) {
                return 'Twitter/X handle must be 1-15 characters using letters, numbers, or underscore.';
            }
        }
        if (in_array($actionType, ['add-schema', 'add-schema-markup'], true)) {
            foreach (['schema', 'schema_data'] as $key) {
                if (! array_key_exists($key, $payload)) {
                    continue;
                }
                $value = $payload[$key];
                if (is_array($value)) {
                    continue;
                }
                if (! is_string($value) || trim($value) === '') {
                    return 'Schema payload must be valid JSON.';
                }
                $decoded = json_decode($value, true);
                if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                    return 'Schema payload must be valid JSON.';
                }
                $payload[$key] = $decoded;
            }
        }

        return null;
    }

    private function humanizeLabel(string $value): string
    {
        return ucwords(str_replace(['-', '_'], ' ', trim($value)));
    }

    private function isAuditNoticeDismissed(): bool
    {
        return ((int) get_user_meta(get_current_user_id(), 'seoworkerai_audit_notice_dismissed_until', true)) > time();
    }

    private function isDomainRatingConfirmed(): bool
    {
        return (bool) get_option('seoworkerai_domain_rating_confirmed', false);
    }

    private function updateLocalBriefLinkState(int $briefId, int $postId, string $postUrl, string $postTitle, string $postType, string $articleStatus): void
    {
        global $wpdb;
        $wpdb->update( // phpcs:ignore
            $wpdb->prefix.'seoworkerai_briefs',
            ['linked_wp_post_id' => $postId, 'linked_wp_post_url' => $postUrl, 'linked_wp_post_title' => $postTitle, 'linked_wp_post_type' => $postType, 'article_status' => $articleStatus, 'assignment_status' => 'completed', 'updated_at' => current_time('mysql')],
            ['laravel_content_brief_id' => $briefId],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );
    }

    /** @param array<string,mixed> $source @return array<string,mixed> */
    private function sanitizePostedSiteSettings(array $source): array
    {
        $domainRatingRaw = isset($source['site_settings_domain_rating']) ? trim((string) wp_unslash($source['site_settings_domain_rating'])) : '';
        $domainRating = $domainRatingRaw === '' ? null : max(0, min(100, (int) $domainRatingRaw));

        return [
            'template_id' => isset($source['site_settings_template_id']) ? max(0, (int) $source['site_settings_template_id']) : 0,
            'provider_name' => 'dataforseo',
            'domain_rating' => $domainRating,
            'min_search_volume' => isset($source['site_settings_min_search_volume']) ? max(0, (int) $source['site_settings_min_search_volume']) : 0,
            'max_search_volume' => isset($source['site_settings_max_search_volume']) && $source['site_settings_max_search_volume'] !== '' ? max(0, (int) $source['site_settings_max_search_volume']) : null,
            'max_keyword_difficulty' => isset($source['site_settings_max_keyword_difficulty']) ? max(0, min(100, (int) $source['site_settings_max_keyword_difficulty'])) : 100,
            'preferred_keyword_type' => isset($source['site_settings_preferred_keyword_type']) ? sanitize_text_field((string) wp_unslash($source['site_settings_preferred_keyword_type'])) : '',
            'content_briefs_per_run' => isset($source['site_settings_content_briefs_per_run']) ? max(1, min(10, (int) $source['site_settings_content_briefs_per_run'])) : 3,
            'prefer_low_difficulty' => ! empty($source['site_settings_prefer_low_difficulty']),
            'allow_low_volume' => ! empty($source['site_settings_allow_low_volume']),
            'brand_twitter_handle' => isset($source['site_settings_brand_twitter_handle']) ? sanitize_text_field((string) wp_unslash($source['site_settings_brand_twitter_handle'])) : '',
            'default_social_image_url' => isset($source['site_settings_default_social_image_url']) ? esc_url_raw((string) wp_unslash($source['site_settings_default_social_image_url'])) : '',
            'selection_notes' => isset($source['site_settings_selection_notes']) ? sanitize_textarea_field((string) wp_unslash($source['site_settings_selection_notes'])) : '',
        ];
    }

    /** @param array<string,mixed> $settings @return array<string,mixed> */
    private function buildRemoteSiteSettingsPayload(array $settings): array
    {
        $payload = [
            'provider_name' => 'dataforseo',
            'domain_rating' => ($settings['domain_rating'] ?? null) !== null ? (int) $settings['domain_rating'] : null,
            'min_search_volume' => (int) ($settings['min_search_volume'] ?? 0),
            'max_search_volume' => $settings['max_search_volume'] ?? null,
            'max_keyword_difficulty' => (int) ($settings['max_keyword_difficulty'] ?? 100),
            'preferred_keyword_type' => (string) ($settings['preferred_keyword_type'] ?? ''),
            'content_briefs_per_run' => (int) ($settings['content_briefs_per_run'] ?? 3),
            'prefer_low_difficulty' => ! empty($settings['prefer_low_difficulty']),
            'allow_low_volume' => ! empty($settings['allow_low_volume']),
            'brand_twitter_handle' => ($settings['brand_twitter_handle'] ?? '') !== '' ? (string) $settings['brand_twitter_handle'] : null,
            'default_social_image_url' => ($settings['default_social_image_url'] ?? '') !== '' ? (string) $settings['default_social_image_url'] : null,
            'selection_notes' => (string) ($settings['selection_notes'] ?? ''),
        ];
        if (! empty($settings['template_id'])) {
            $payload['template_id'] = (int) $settings['template_id'];
        }
        if ($payload['preferred_keyword_type'] === '') {
            unset($payload['preferred_keyword_type']);
        }

        return $payload;
    }

    /** @param array<string,mixed> $source */
    private function savePostedAuthorProfiles(array $source): void
    {
        $profiles = isset($source['author_profiles']) && is_array($source['author_profiles']) ? wp_unslash($source['author_profiles']) : [];
        foreach ($profiles as $userId => $profile) {
            $resolvedUserId = (int) $userId;
            if ($resolvedUserId <= 0 || ! is_array($profile)) {
                continue;
            }
            $twitterHandle = isset($profile['twitter_handle']) ? sanitize_text_field((string) $profile['twitter_handle']) : '';
            if ($twitterHandle !== '' && ! preg_match('/^@?[A-Za-z0-9_]{1,15}$/', $twitterHandle)) {
                continue;
            }
            update_user_meta($resolvedUserId, '_seoworkerai_author_twitter_handle', $twitterHandle);
        }
    }

    /** @return list<array{user_id:int,display_name:string,email:string,twitter_handle:string}> */
    private function getAuthorProfiles(): array
    {
        $users = get_users(['role__in' => ['administrator', 'editor', 'author', 'contributor'], 'orderby' => 'display_name', 'order' => 'ASC']);
        if (! is_array($users)) {
            return [];
        }
        $profiles = [];
        foreach ($users as $user) {
            if (! $user instanceof \WP_User) {
                continue;
            }
            $profiles[] = ['user_id' => (int) $user->ID, 'display_name' => (string) $user->display_name, 'email' => (string) $user->user_email, 'twitter_handle' => (string) get_user_meta($user->ID, '_seoworkerai_author_twitter_handle', true)];
        }

        return $profiles;
    }

    /** @param array<string,mixed> $source @return list<array{location_type:string,location_code:int,location_name:string,priority:int}> */
    private function sanitizePostedLocations(array $source): array
    {
        $rows = isset($source['site_locations']) && is_array($source['site_locations']) ? wp_unslash($source['site_locations']) : [];
        $available = [];
        foreach ($this->getAvailableLocationOptions() as $option) {
            $available[(int) $option['code']] = (string) $option['name'];
        }
        $locations = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $locationCode = isset($row['location_code']) ? (int) $row['location_code'] : 0;
            if ($locationCode <= 0 || ! isset($available[$locationCode])) {
                continue;
            }
            $locationType = isset($row['location_type']) ? sanitize_text_field((string) $row['location_type']) : 'secondary';
            if (! in_array($locationType, ['primary', 'secondary'], true)) {
                $locationType = 'secondary';
            }
            $locations[] = ['location_type' => $locationType, 'location_code' => $locationCode, 'location_name' => $available[$locationCode], 'priority' => 0];
        }

        return $this->siteRegistrar->normalizeLocationsOption($locations);
    }

    /** @return list<array{code:int,name:string,label:string}> */
    private function getAvailableLocationOptions(): array
    {
        return [
            ['code' => 2036, 'name' => 'Australia',             'label' => 'Australia (2036)'],
            ['code' => 2124, 'name' => 'Canada',                'label' => 'Canada (2124)'],
            ['code' => 2356, 'name' => 'India',                 'label' => 'India (2356)'],
            ['code' => 2504, 'name' => 'Morocco',               'label' => 'Morocco (2504)'],
            ['code' => 2554, 'name' => 'New Zealand',           'label' => 'New Zealand (2554)'],
            ['code' => 2586, 'name' => 'Pakistan',              'label' => 'Pakistan (2586)'],
            ['code' => 2682, 'name' => 'Saudi Arabia',          'label' => 'Saudi Arabia (2682)'],
            ['code' => 2702, 'name' => 'Singapore',             'label' => 'Singapore (2702)'],
            ['code' => 2784, 'name' => 'United Arab Emirates',  'label' => 'United Arab Emirates (2784)'],
            ['code' => 2826, 'name' => 'United Kingdom',        'label' => 'United Kingdom (2826)'],
            ['code' => 2840, 'name' => 'United States',         'label' => 'United States (2840)'],
        ];
    }

    /** @param array<string,mixed> $payload @return list<string> */
    private function collectInsightItemsByType(array $payload, string $type, int $limit): array
    {
        $items = [];
        foreach ((array) ($payload['insights'] ?? []) as $insight) {
            if (! is_array($insight) || (string) ($insight['insight_type'] ?? '') !== $type) {
                continue;
            }
            $v = trim((string) ($insight['details'] ?? $insight['headline'] ?? ''));
            if ($v === '') {
                continue;
            }
            $items[] = $v;
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /** @return array{issues_found:int,applied:int,needs_human_review:int,pending_count:int,is_sync_pending:bool} */
    private function getInitialAuditMetrics(): array
    {
        global $wpdb;
        $actionsTable = $wpdb->prefix.'seoworkerai_actions';

        $row = $wpdb->get_row( // phpcs:ignore
            "SELECT
                COUNT(*) AS issues_found,
                SUM(CASE WHEN action_type != 'human-action-required' AND status = 'applied' THEN 1 ELSE 0 END) AS applied,
                SUM(CASE WHEN action_type = 'human-action-required' THEN 1 ELSE 0 END) AS needs_human_review
            FROM {$actionsTable}",
            ARRAY_A
        );

        $issuesFound = max(0, (int) ($row['issues_found'] ?? 0));
        $applied = max(0, (int) ($row['applied'] ?? 0));
        $needsHumanReview = max(0, (int) ($row['needs_human_review'] ?? 0));

        $pendingCount = (int) $wpdb->get_var( // phpcs:ignore
            "SELECT COUNT(*) FROM {$actionsTable}
             WHERE action_type != 'human-action-required'
               AND status IN ('received','queued','running','ack_pending','ack_failed')"
        );

        return [
            'issues_found' => $issuesFound,
            'applied' => $applied,
            'needs_human_review' => $needsHumanReview,
            'pending_count' => max(0, $pendingCount),
            'is_sync_pending' => $pendingCount > 0,
        ];
    }

    public function sanitizeBaseUrl($value): string
    {
        return rtrim((string) SEOWORKERAI_LARAVEL_BASE_URL, '/');
    }

    public function sanitizeChangeApplicationMode($value): string
    {
        $mode = trim((string) $value);

        return in_array($mode, ['dangerous_auto_apply', 'review_before_apply'], true) ? $mode : 'dangerous_auto_apply';
    }

    public function sanitizeExcludedChangeAuditPages($value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }
        $tokens = preg_split('/[\r\n,]+/', $raw) ?: [];
        $clean = [];
        foreach ($tokens as $token) {
            $n = trim((string) $token);
            if ($n !== '') {
                $clean[] = sanitize_text_field($n);
            }
        }

        return implode("\n", array_values(array_unique($clean)));
    }

    private function isBaseUrlSyntaxValid(string $url): bool
    {
        $scheme = wp_parse_url($url, PHP_URL_SCHEME);
        $host = wp_parse_url($url, PHP_URL_HOST);
        if (! is_string($scheme) || ! in_array(strtolower($scheme), ['http', 'https'], true)) {
            return false;
        }

        return is_string($host) && $host !== '';
    }
}
