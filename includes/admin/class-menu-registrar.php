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
    ) {
        $this->client            = $client;
        $this->briefSyncer       = $briefSyncer;
        $this->siteRegistrar     = $siteRegistrar;
        $this->healthChecker     = $healthChecker;
        $this->oauthHandler      = $oauthHandler;
        $this->tokenManager      = $tokenManager;
        $this->actionRepository  = $actionRepository;
        $this->actionExecutor    = $actionExecutor;
        $this->logger            = $logger;
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
        wp_enqueue_style('seoauto-admin', SEOAUTO_PLUGIN_URL . 'assets/css/admin.css', [], SEOAUTO_VERSION);
        wp_enqueue_script('seoauto-admin', SEOAUTO_PLUGIN_URL . 'assets/js/admin.js', [], SEOAUTO_VERSION, true);
    }

    // ─── Form handlers ────────────────────────────────────────────────────────

    public function handleRegisterSite(): void
    {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('seoauto_register_site');
        $baseUrl = trim((string) get_option('seoauto_base_url', ''));
        if ($baseUrl === '' || !$this->isBaseUrlSyntaxValid($baseUrl)) {
            wp_safe_redirect(add_query_arg(['page' => 'seoauto-settings', 'seoauto_notice' => 'register_missing_base_url'], admin_url('admin.php')));
            exit;
        }
        $result = $this->siteRegistrar->registerOrUpdate(true);
        $ok = !isset($result['error']) && (!empty($result['site_id']) || ((int) get_option('seoauto_site_id', 0) > 0));
        wp_safe_redirect(add_query_arg(['page' => 'seoauto-settings', 'seoauto_notice' => $ok ? 'register_ok' : 'register_failed'], admin_url('admin.php')));
        exit;
    }

    public function handleHealthCheck(): void
    {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('seoauto_health_check');
        $result = $this->healthChecker->check();
        $ok = !empty($result['connected']);
        wp_safe_redirect(add_query_arg(['page' => 'seoauto-settings', 'seoauto_notice' => $ok ? 'health_ok' : 'health_failed'], admin_url('admin.php')));
        exit;
    }

    public function handleStartOAuth(): void
    {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('seoauto_start_oauth');
        try {
            $oauthUrl = $this->oauthHandler->beginGoogleOAuth(['search_console', 'analytics']);
            if ($oauthUrl === '') throw new \RuntimeException('Missing oauth_url');
            wp_redirect($oauthUrl);
            exit;
        } catch (\Throwable $e) {
            update_option('seoauto_oauth_status', 'failed', false);
            update_option('seoauto_oauth_last_error', $e->getMessage(), false);
            wp_safe_redirect(add_query_arg(['page' => 'seoauto-settings', 'seoauto_notice' => 'oauth_init_failed'], admin_url('admin.php')));
            exit;
        }
    }

    public function handleRevokeOAuth(): void
    {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('seoauto_revoke_oauth');
        try {
            $reason = isset($_POST['revocation_reason']) ? sanitize_text_field((string) $_POST['revocation_reason']) : '';
            $this->client->revokeGoogleOAuth($reason !== '' ? ['revocation_reason' => $reason] : []);
            $notice = 'oauth_revoke_ok';
        } catch (\Throwable $e) {
            $notice = (int) $e->getCode() === 404 ? 'oauth_revoke_ok' : 'oauth_revoke_failed';
            if ($notice !== 'oauth_revoke_ok') {
                update_option('seoauto_oauth_last_error', $e->getMessage(), false);
            }
        }
        if (in_array($notice, ['oauth_revoke_ok'], true)) {
            update_option('seoauto_oauth_status', 'pending', false);
            update_option('seoauto_oauth_provider', '', false);
            update_option('seoauto_oauth_scopes', [], false);
            update_option('seoauto_oauth_connected_at', 0, false);
            update_option('seoauto_oauth_last_error', '', false);
        }
        wp_safe_redirect(add_query_arg(['page' => 'seoauto-settings', 'seoauto_notice' => $notice], admin_url('admin.php')));
        exit;
    }

    public function handleRotateToken(): void
    {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('seoauto_rotate_token');
        $siteId = (int) get_option('seoauto_site_id', 0);
        if ($siteId <= 0) {
            wp_safe_redirect(add_query_arg(['page' => 'seoauto-settings', 'seoauto_notice' => 'rotate_failed'], admin_url('admin.php')));
            exit;
        }
        try {
            $response = $this->client->rotateSiteToken($siteId);
            $newToken = isset($response['api_key']) ? (string) $response['api_key'] : '';
            if ($newToken === '') throw new \RuntimeException('Token rotation response missing api_key.');
            $this->tokenManager->storeToken($newToken);
            update_option('seoauto_oauth_last_error', '', false);
            $notice = 'rotate_ok';
        } catch (\Throwable $e) {
            update_option('seoauto_oauth_last_error', $e->getMessage(), false);
            $notice = 'rotate_failed';
        }
        wp_safe_redirect(add_query_arg(['page' => 'seoauto-settings', 'seoauto_notice' => $notice], admin_url('admin.php')));
        exit;
    }

    public function handleUpdateSiteProfile(): void
    {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('seoauto_update_site_profile');
        $description = isset($_POST['site_profile_description']) ? sanitize_textarea_field((string) wp_unslash($_POST['site_profile_description'])) : '';
        $taste = isset($_POST['site_profile_taste']) ? sanitize_textarea_field((string) wp_unslash($_POST['site_profile_taste'])) : '';
        $locations = $this->sanitizePostedLocations($_POST);
        $primaryLocation = $locations[0] ?? [
            'location_type' => 'primary',
            'location_code' => 2840,
            'location_name' => 'United States',
            'priority' => 0,
        ];

        update_option('seoauto_site_profile_description', $description, false);
        update_option('seoauto_site_profile_taste', $taste, false);
        update_option('seoauto_site_locations', $locations, false);
        update_option('seoauto_site_location_code', (int) $primaryLocation['location_code'], false);
        update_option('seoauto_site_location_name', (string) $primaryLocation['location_name'], false);

        $siteSettings = $this->sanitizePostedSiteSettings($_POST);
        update_option('seoauto_site_seo_settings', $siteSettings, false);

        $result = $this->siteRegistrar->registerOrUpdate(true);
        $notice = 'profile_ok';

        if (isset($result['error'])) {
            $notice = 'profile_failed';
        } else {
            $siteId = (int) ($result['site_id'] ?? get_option('seoauto_site_id', 0));
            if ($siteId > 0) {
                try {
                    $settingsResponse = $this->client->updateSiteSettings($siteId, $this->buildRemoteSiteSettingsPayload($siteSettings));
                    if (isset($settingsResponse['settings']) && is_array($settingsResponse['settings'])) {
                        update_option(
                            'seoauto_site_seo_settings',
                            $this->siteRegistrar->sanitizeSiteSettingsPayload($settingsResponse['settings']),
                            false
                        );
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('admin_update_site_settings_failed', ['error' => $e->getMessage()], 'admin');
                    $notice = 'profile_failed';
                }
            }
        }

        wp_safe_redirect(add_query_arg(['page' => 'seoauto-settings', 'seoauto_notice' => $notice], admin_url('admin.php')));
        exit;
    }

    public function handleUpdateTask(): void
    {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('seoauto_update_task');
        $taskId = isset($_POST['task_id']) ? (int) $_POST['task_id'] : 0;
        if ($taskId <= 0) {
            wp_safe_redirect(add_query_arg(['page' => 'seoauto-schedules', 'seoauto_notice' => 'task_update_failed'], admin_url('admin.php')));
            exit;
        }
        try {
            $isEnabled = !empty($_POST['is_enabled']);
            $delayMinutes = isset($_POST['delay_minutes']) ? max(0, (int) $_POST['delay_minutes']) : 0;
            $this->client->updateTaskConfig($taskId, ['is_enabled' => $isEnabled, 'delay_minutes' => $delayMinutes]);
            $notice = 'task_update_ok';
        } catch (\Throwable $e) {
            $this->logger->warning('admin_task_update_failed', ['error' => $e->getMessage()], 'admin');
            $notice = 'task_update_failed';
        }
        wp_safe_redirect(add_query_arg(['page' => 'seoauto-schedules', 'seoauto_notice' => $notice], admin_url('admin.php')));
        exit;
    }

    public function handleScheduleTask(): void
    {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('seoauto_schedule_task');
        $taskId = isset($_POST['task_id']) ? (int) $_POST['task_id'] : 0;
        if ($taskId <= 0) {
            wp_safe_redirect(add_query_arg(['page' => 'seoauto-schedules', 'seoauto_notice' => 'task_schedule_failed'], admin_url('admin.php')));
            exit;
        }
        $payload = [];
        $scheduledFor = isset($_POST['scheduled_for']) ? sanitize_text_field((string) $_POST['scheduled_for']) : '';
        if ($scheduledFor !== '') {
            $ts = strtotime($scheduledFor);
            if ($ts !== false) $payload['scheduled_for'] = gmdate('c', $ts);
        }
        $inputJson = isset($_POST['input_params_json']) ? trim((string) $_POST['input_params_json']) : '';
        if ($inputJson !== '') {
            $decoded = json_decode($inputJson, true);
            if (is_array($decoded)) $payload['input_params'] = $decoded;
        }
        try {
            $this->client->scheduleTask($taskId, $payload);
            $notice = 'task_schedule_ok';
        } catch (\Throwable $e) {
            $this->logger->warning('admin_task_schedule_failed', ['error' => $e->getMessage()], 'admin');
            $notice = 'task_schedule_failed';
        }
        wp_safe_redirect(add_query_arg(['page' => 'seoauto-schedules', 'seoauto_notice' => $notice], admin_url('admin.php')));
        exit;
    }

    public function handleLinkBrief(): void
    {
        if (!current_user_can('edit_posts')) wp_die('Unauthorized');
        check_admin_referer('seoauto_link_brief');
        $briefId = isset($_POST['brief_id']) ? (int) $_POST['brief_id'] : 0;
        $postId  = isset($_POST['wp_post_id']) ? (int) $_POST['wp_post_id'] : 0;
        $articleStatus = isset($_POST['article_status']) ? sanitize_text_field((string) $_POST['article_status']) : 'drafted';
        if (!in_array($articleStatus, ['drafted', 'published'], true)) $articleStatus = 'drafted';
        $siteId = (int) get_option('seoauto_site_id', 0);
        if ($briefId <= 0 || $postId <= 0 || $siteId <= 0) {
            wp_safe_redirect(add_query_arg(['page' => 'seoauto-briefs', 'seoauto_notice' => 'brief_link_failed'], admin_url('admin.php')));
            exit;
        }
        $post = get_post($postId);
        if (!$post instanceof \WP_Post) {
            wp_safe_redirect(add_query_arg(['page' => 'seoauto-briefs', 'seoauto_notice' => 'brief_link_failed'], admin_url('admin.php')));
            exit;
        }
        try {
            $payload = [
                'wp_post_id'    => $postId,
                'wp_post_url'   => get_permalink($postId),
                'wp_post_title' => get_the_title($postId),
                'wp_post_type'  => get_post_type($postId),
                'article_status' => $articleStatus,
                'published_at'  => get_post_status($postId) === 'publish' ? gmdate('c', (int) get_post_time('U', true, $postId)) : null,
            ];
            $this->client->linkArticleToBrief($siteId, $briefId, $payload);
            $this->updateLocalBriefLinkState(
                $briefId,
                $postId,
                (string) get_permalink($postId),
                (string) get_the_title($postId),
                (string) get_post_type($postId),
                $articleStatus
            );
            $notice = 'brief_link_ok';
        } catch (\Throwable $e) {
            $this->logger->warning('admin_brief_link_failed', ['error' => $e->getMessage()], 'admin');
            $notice = 'brief_link_failed';
        }
        wp_safe_redirect(add_query_arg(['page' => 'seoauto-briefs', 'seoauto_notice' => $notice], admin_url('admin.php')));
        exit;
    }

    public function handleDeleteLogs(): void
    {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('seoauto_delete_logs');
        global $wpdb;
        $table = $wpdb->prefix . 'seoauto_changes';
        $deleted = $wpdb->query("DELETE FROM {$table}"); // phpcs:ignore
        $notice = $deleted === false ? 'logs_delete_failed' : 'logs_delete_ok';
        $deletedCount = $deleted === false ? 0 : (int) $deleted;
        wp_safe_redirect(add_query_arg(['page' => 'seoauto-logs', 'seoauto_notice' => $notice, 'deleted_count' => $deletedCount], admin_url('admin.php')));
        exit;
    }

    public function handleDeleteLocalErrors(): void
    {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('seoauto_delete_local_errors');
        $severity = isset($_POST['severity']) ? sanitize_text_field((string) $_POST['severity']) : 'all';
        $allowed = ['all', 'error', 'warning'];
        if (!in_array($severity, $allowed, true)) $severity = 'all';
        global $wpdb;
        $table = $wpdb->prefix . 'seoauto_logs';
        if ($severity === 'error') {
            $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE severity = %s", 'error')); // phpcs:ignore
        } elseif ($severity === 'warning') {
            $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE severity = %s", 'warning')); // phpcs:ignore
        } else {
            $deleted = $wpdb->query("DELETE FROM {$table} WHERE severity IN ('warning','error')"); // phpcs:ignore
        }
        $notice = $deleted === false ? 'local_errors_delete_failed' : 'local_errors_delete_ok';
        $deletedCount = $deleted === false ? 0 : (int) $deleted;
        wp_safe_redirect(add_query_arg(['page' => 'seoauto-local-errors', 'seoauto_notice' => $notice, 'deleted_count' => $deletedCount, 'severity' => $severity], admin_url('admin.php')));
        exit;
    }

    public function handleApplyAction(): void
    {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('seoauto_apply_action');
        $actionId = isset($_POST['action_id']) ? (int) $_POST['action_id'] : 0;
        if ($actionId > 0) {
            \SEOAutomation\Connector\Queue\QueueManager::enqueueActionExecution($actionId, 50);
            $this->actionRepository->markQueued($actionId);
        }
        $returnPage = $this->resolveActionRedirectPage();
        wp_safe_redirect(add_query_arg(['page' => $returnPage, 'seoauto_notice' => 'action_apply_requested'], admin_url('admin.php')));
        exit;
    }

    public function handleRevertAction(): void
    {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('seoauto_revert_action');
        $actionId = isset($_POST['action_id']) ? (int) $_POST['action_id'] : 0;
        $notice = 'action_revert_failed';
        if ($actionId > 0) {
            try {
                $result = $this->actionExecutor->revertByLaravelId($actionId);
                if (($result['status'] ?? '') === 'rolled_back') {
                    $notice = 'action_revert_ok';
                } else {
                    $error = trim((string)($result['error'] ?? 'Rollback failed.'));
                    $this->actionRepository->logAdminFailure($actionId, 'Rollback failed: ' . $error, ['source' => 'manual_revert']);
                    $this->logger->warning('admin_revert_failed', [
                        'entity_type' => 'action',
                        'entity_id' => (string)$actionId,
                        'error' => $error,
                    ], 'admin');
                }
            } catch (\Throwable $e) {
                $this->actionRepository->logAdminFailure($actionId, 'Rollback failed: ' . $e->getMessage(), ['source' => 'manual_revert_exception']);
                $this->logger->error('admin_revert_exception', [
                    'entity_type' => 'action',
                    'entity_id' => (string)$actionId,
                    'error' => $e->getMessage(),
                ], 'admin');
            }
        }
        $returnPage = $this->resolveActionRedirectPage();
        wp_safe_redirect(add_query_arg(['page' => $returnPage, 'seoauto_notice' => $notice], admin_url('admin.php')));
        exit;
    }

    public function handleEditActionPayload(): void
    {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('seoauto_edit_action_payload');
        $actionId     = isset($_POST['action_id']) ? (int) $_POST['action_id'] : 0;
        $payloadJson  = isset($_POST['payload_json']) ? (string) wp_unslash($_POST['payload_json']) : '';
        $payloadFields = isset($_POST['payload_fields']) && is_array($_POST['payload_fields']) ? wp_unslash($_POST['payload_fields']) : [];
        $payload = json_decode($payloadJson, true);
        $notice = 'action_edit_failed';
        if ($actionId > 0) {
            $action = $this->actionRepository->findByLaravelId($actionId);
            $basePayload = [];
            $actionType = is_array($action) ? (string)($action['action_type'] ?? '') : '';
            if (is_array($action)) {
                $decoded = json_decode((string) ($action['action_payload'] ?? '{}'), true);
                $basePayload = is_array($decoded) ? $decoded : [];
            }
            try {
                if (is_array($payloadFields) && !empty($payloadFields)) {
                    $payload = $this->applyPayloadFieldEdits($basePayload, $payloadFields);
                }

                if ($payloadJson !== '' && !is_array($payload)) {
                    throw new \RuntimeException('Payload JSON is invalid.');
                }

                if (!is_array($payload)) {
                    throw new \RuntimeException('No editable payload received.');
                }

                $validationError = $this->validateEditedPayload($actionType, $payload);
                if ($validationError !== null) {
                    $this->actionRepository->logAdminFailure($actionId, 'Edit failed validation: ' . $validationError, ['source' => 'payload_validation']);
                    $this->logger->warning('admin_edit_validation_failed', [
                        'entity_type' => 'action',
                        'entity_id' => (string)$actionId,
                        'error' => $validationError,
                    ], 'admin');
                    $notice = 'action_edit_validation_failed';
                } else {
                    $updated = $this->actionRepository->updatePayload($actionId, $payload, get_current_user_id());
                    if ($updated) {
                        $notice = 'action_edit_ok';
                        if (is_array($action) && ($action['status'] ?? '') === 'applied') {
                            \SEOAutomation\Connector\Queue\QueueManager::enqueueActionExecution($actionId, 10);
                            $this->actionRepository->markQueued($actionId);
                            $notice = 'action_edit_ok_reapply';
                        }
                    } else {
                        $this->actionRepository->logAdminFailure($actionId, 'Edit failed: payload update returned false.', ['source' => 'payload_update']);
                    }
                }
            } catch (\Throwable $e) {
                $this->actionRepository->logAdminFailure($actionId, 'Edit failed: ' . $e->getMessage(), ['source' => 'payload_exception']);
                $this->logger->error('admin_edit_exception', [
                    'entity_type' => 'action',
                    'entity_id' => (string)$actionId,
                    'error' => $e->getMessage(),
                ], 'admin');
            }
        }
        wp_safe_redirect(add_query_arg(['page' => 'seoauto-logs', 'seoauto_notice' => $notice], admin_url('admin.php')));
        exit;
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $fields @return array<string,mixed> */
    private function applyPayloadFieldEdits(array $payload, array $fields): array
    {
        foreach ($fields as $key => $value) {
            $k = sanitize_text_field((string) $key);
            $v = is_scalar($value) ? sanitize_textarea_field((string) $value) : '';
            if ($k === 'meta_description') { $payload['meta_description'] = $v; $payload['recommended_meta_description'] = $v; continue; }
            if ($k === 'alt_text') { $payload['alt_text'] = $v; $payload['suggested_alt'] = $v; continue; }
            if ($k === 'seo_title') { $payload['seo_title'] = $v; $payload['title'] = $v; continue; }
            if ($k === 'social_tags_og_title') { $payload['social_tags']['og']['title'] = $v; continue; }
            if ($k === 'social_tags_og_description') { $payload['social_tags']['og']['description'] = $v; continue; }
            if ($k === 'social_tags_twitter_site') { $payload['social_tags']['twitter']['site'] = $v; continue; }
            $payload[$k] = $v;
        }
        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function validateEditedPayload(string $actionType, array $payload): ?string
    {
        if (in_array($actionType, ['add-meta-description', 'update-meta-description'], true)) {
            $meta = trim((string)($payload['meta_description'] ?? $payload['recommended_meta_description'] ?? ''));
            $len = function_exists('mb_strlen') ? mb_strlen($meta) : strlen($meta);
            if ($len < 70 || $len > 160) {
                return 'Meta description must be between 70 and 160 characters.';
            }
        }

        if ($actionType === 'set-social-tags') {
            $ogDesc = trim((string)($payload['social_tags']['og']['description'] ?? ''));
            $ogDescLen = function_exists('mb_strlen') ? mb_strlen($ogDesc) : strlen($ogDesc);
            if ($ogDesc !== '' && $ogDescLen > 200) {
                return 'OG description must be 200 characters or fewer.';
            }

            $twitterSite = trim((string)($payload['social_tags']['twitter']['site'] ?? ''));
            if ($twitterSite !== '' && !preg_match('/^@?[A-Za-z0-9_]{1,15}$/', $twitterSite)) {
                return 'Twitter/X handle must be 1-15 characters using letters, numbers, or underscore.';
            }
        }

        if (in_array($actionType, ['add-schema', 'add-schema-markup'], true)) {
            foreach (['schema', 'schema_data'] as $key) {
                if (!array_key_exists($key, $payload)) {
                    continue;
                }
                $value = $payload[$key];
                if (is_array($value)) {
                    continue;
                }
                if (!is_string($value) || trim($value) === '') {
                    return 'Schema payload must be valid JSON.';
                }
                $decoded = json_decode($value, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                    return 'Schema payload must be valid JSON.';
                }
                $payload[$key] = $decoded;
            }
        }

        return null;
    }

    public function handleUpdateActionItem(): void
    {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('seoauto_update_action_item');
        global $wpdb;
        $table  = $wpdb->prefix . 'seoauto_action_items';
        $itemId = isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0;
        $status = isset($_POST['status']) ? sanitize_text_field((string) $_POST['status']) : 'open';
        $valid  = ['open', 'in_progress', 'resolved'];
        if ($itemId > 0 && in_array($status, $valid, true)) {
            $wpdb->update( // phpcs:ignore
                $table,
                ['status' => $status, 'resolved_at' => $status === 'resolved' ? current_time('mysql') : null, 'updated_at' => current_time('mysql')],
                ['id' => $itemId],
                ['%s', '%s', '%s'],
                ['%d']
            );
        }
        wp_safe_redirect(add_query_arg(['page' => 'seoauto-action-items', 'seoauto_notice' => 'action_item_updated'], admin_url('admin.php')));
        exit;
    }

    // ─── Menu Registration ────────────────────────────────────────────────────

    public function registerMenu(): void
    {
        add_menu_page('SEO Automation', 'SEO Automation', 'manage_options', 'seoauto', [$this, 'renderSettingsPage'], 'dashicons-performance', 80);
        add_submenu_page('seoauto', 'Settings', 'Settings', 'manage_options', 'seoauto', [$this, 'renderSettingsPage']);
        add_submenu_page('seoauto', 'Change Center', 'Change Center', 'manage_options', 'seoauto-logs', [$this, 'renderLogsPage']);
        add_submenu_page('seoauto', 'Action Items', 'Action Items', 'manage_options', 'seoauto-action-items', [$this, 'renderActionItemsPage']);
        add_submenu_page('seoauto', 'Content Briefs', 'Content Briefs', 'edit_posts', 'seoauto-briefs', [$this, 'renderBriefsPage']);
        
        // Hidden pages - accessible via URL but not in menu (null parent)
        add_submenu_page(null, 'Debug Logs', 'Debug Logs', 'manage_options', 'seoauto-local-errors', [$this, 'renderLocalErrorsPage']);
        add_submenu_page(null, 'Schedules', 'Schedules', 'manage_options', 'seoauto-schedules', [$this, 'renderSchedulesPage']);
        add_submenu_page(null, 'Settings', 'Settings', 'manage_options', 'seoauto-settings', [$this, 'renderSettingsPage']);
        add_submenu_page(null, 'OAuth Callback', 'OAuth Callback', 'manage_options', 'seoauto-oauth-callback', [$this, 'renderOauthCallbackPage']);
        add_submenu_page(null, 'OAuth Callback', 'OAuth Callback', 'manage_options', 'seo-platform-oauth-complete', [$this, 'renderOauthCallbackPage']);
    }

    public function registerSettings(): void
    {
        register_setting('seoauto_settings', 'seoauto_primary_seo_adapter', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'auto']);
        register_setting('seoauto_settings', 'seoauto_change_application_mode', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitizeChangeApplicationMode'], 'default' => 'dangerous_auto_apply']);
        register_setting('seoauto_settings', 'seoauto_debug_enabled', ['type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => false]);
        register_setting('seoauto_settings', 'seoauto_allow_insecure_ssl', ['type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => false]);
        register_setting('seoauto_settings', 'seoauto_excluded_change_audit_pages', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitizeExcludedChangeAuditPages'], 'default' => '']);
    }

    // ─── Render: Settings ─────────────────────────────────────────────────────

    public function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');

        $notice         = isset($_GET['seoauto_notice']) ? sanitize_text_field((string) $_GET['seoauto_notice']) : '';
        $oauthStatus    = (string) get_option('seoauto_oauth_status', 'pending');
        $oauthProvider  = (string) get_option('seoauto_oauth_provider', '');
        $oauthScopes    = get_option('seoauto_oauth_scopes', []);
        if (!is_array($oauthScopes)) $oauthScopes = [];
        $oauthConnectedAt = (int) get_option('seoauto_oauth_connected_at', 0);
        $oauthError     = (string) get_option('seoauto_oauth_last_error', '');
        $adapter        = (string) get_option('seoauto_primary_seo_adapter', 'auto');
        $mode           = (string) get_option('seoauto_change_application_mode', 'dangerous_auto_apply');
        $siteId         = (int) get_option('seoauto_site_id', 0);
        $lastCron       = (int) get_option('seoauto_last_cron_run', 0);
        $lastUserSync   = (int) get_option('seoauto_last_user_sync', 0);
        $lastBriefSync  = (int) get_option('seoauto_last_brief_sync', 0);
        $siteDescription = (string) get_option('seoauto_site_profile_description', '');
        $siteTaste = (string) get_option('seoauto_site_profile_taste', '');
        $siteLocations = $this->siteRegistrar->normalizeLocationsOption(get_option('seoauto_site_locations', []));
        if ($siteLocations === []) {
            $siteLocations = [[
                'location_type' => 'primary',
                'location_code' => (int) get_option('seoauto_site_location_code', 2840),
                'location_name' => (string) get_option('seoauto_site_location_name', 'United States'),
                'priority' => 0,
            ]];
        }
        $siteSeoSettings = get_option('seoauto_site_seo_settings', []);
        if (!is_array($siteSeoSettings)) $siteSeoSettings = [];
        $siteSettingTemplates = [];
        $availableLocations = $this->getAvailableLocationOptions();
        $domainRatingCheckedAt = !empty($siteSeoSettings['domain_rating_checked_at'])
            ? strtotime((string) $siteSeoSettings['domain_rating_checked_at'])
            : false;

        $excludedRaw    = (string) get_option('seoauto_excluded_change_audit_pages', '');
        $excludedItems  = array_values(array_filter(array_map('trim', explode("\n", $excludedRaw))));

        $isConnected    = $oauthStatus === 'active';

        $providerAlerts = get_option('seoauto_provider_connection_alerts', []);
        if (!is_array($providerAlerts)) $providerAlerts = [];

        if ($siteId > 0 && $this->tokenManager->hasToken()) {
            try {
                $settingsResponse = $this->client->getSiteSettings($siteId);
                if (isset($settingsResponse['settings']) && is_array($settingsResponse['settings'])) {
                    $siteSeoSettings = $this->siteRegistrar->sanitizeSiteSettingsPayload($settingsResponse['settings']);
                    update_option('seoauto_site_seo_settings', $siteSeoSettings, false);
                }
                $siteSettingTemplates = isset($settingsResponse['templates']) && is_array($settingsResponse['templates']) ? $settingsResponse['templates'] : [];
                if (isset($settingsResponse['locations']) && is_array($settingsResponse['locations'])) {
                    $siteLocations = $this->siteRegistrar->normalizeLocationsOption($settingsResponse['locations']);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('admin_fetch_site_settings_failed', ['error' => $e->getMessage()], 'admin');
            }
        }

        // Fetch all published posts + pages for the exclusion tag UI
        $allPosts = get_posts([
            'post_type'      => ['post', 'page'],
            'post_status'    => 'publish',
            'posts_per_page' => 500,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'fields'         => 'ids',
        ]);
        $excludedIds = [];
        foreach ($excludedItems as $item) {
            if (ctype_digit(trim($item))) {
                $excludedIds[] = (int) trim($item);
            }
        }
        ?>
        <div class="wrap seoauto-admin-page">
            <?php $this->renderAdminShellHeader('SEO Automation', 'seoauto', 'Manage your site\'s SEO automation preferences and integrations.'); ?>

            <?php $this->renderNotice($notice); ?>

            <?php if (!empty($providerAlerts)) : foreach ($providerAlerts as $key => $alert) : if (!is_array($alert)) continue; ?>
                <div class="notice notice-warning">
                    <p><strong><?php echo esc_html((string)($alert['provider_name'] ?? $key)); ?> issue:</strong> <?php echo esc_html((string)($alert['message'] ?? '')); ?></p>
                    <p><?php echo esc_html((string)($alert['resolution_hint'] ?? '')); ?></p>
                </div>
            <?php endforeach; endif; ?>

            <?php if ((bool) get_option('seoauto_api_blocked', false)) : ?>
                <div class="notice notice-error">
                    <p><strong>Connection error:</strong> <?php echo esc_html((string)get_option('seoauto_api_last_error', 'Unknown error')); ?></p>
                </div>
            <?php endif; ?>

            <!-- Site Status Banner -->
            <div class="seoauto-card seoauto-card-wide" style="margin-bottom:16px;">
                <div class="seoauto-card-head">
                    <h2>Site Status</h2>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=seoauto-briefs')); ?>">View Content Briefs</a>
                </div>
                <div class="seoauto-stat-grid">
                    <div class="seoauto-stat"><span>Registration</span><strong><?php echo esc_html($siteId > 0 ? 'Active' : 'Not registered'); ?></strong></div>
                    <div class="seoauto-stat"><span>Queue Heartbeat</span><strong><?php echo esc_html($lastCron > 0 ? wp_date('Y-m-d H:i', $lastCron) : 'Never'); ?></strong></div>
                    <div class="seoauto-stat"><span>User Sync</span><strong><?php echo esc_html($lastUserSync > 0 ? wp_date('Y-m-d H:i', $lastUserSync) : 'Never'); ?></strong></div>
                    <div class="seoauto-stat"><span>Brief Sync</span><strong><?php echo esc_html($lastBriefSync > 0 ? wp_date('Y-m-d H:i', $lastBriefSync) : 'Never'); ?></strong></div>
                    <div class="seoauto-stat"><span>Google Connection</span><strong><?php echo esc_html($isConnected ? 'Connected' . ($oauthProvider !== '' ? ' (' . $oauthProvider . ')' : '') : 'Not connected'); ?></strong></div>
                </div>
            </div>

            <div class="seoauto-settings-grid">

                <section class="seoauto-card seoauto-card--settings">
                    <h2>Site Profile &amp; Strategy</h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('seoauto_update_site_profile'); ?>
                        <input type="hidden" name="action" value="seoauto_update_site_profile">

                        <div class="seoauto-form-field">
                            <label for="seoauto-site-profile-description">Site Description</label>
                            <textarea id="seoauto-site-profile-description" name="site_profile_description" rows="4"><?php echo esc_textarea($siteDescription); ?></textarea>
                        </div>
                        <div class="seoauto-form-field">
                            <label for="seoauto-site-profile-taste">Brand Taste</label>
                            <textarea id="seoauto-site-profile-taste" name="site_profile_taste" rows="4"><?php echo esc_textarea($siteTaste); ?></textarea>
                        </div>
                        <div class="seoauto-form-field">
                            <label>Locations</label>
                            <div class="seoauto-locations-table-wrap" data-location-options="<?php echo esc_attr(wp_json_encode(array_values($availableLocations))); ?>">
                                <table class="widefat seoauto-locations-table">
                                    <thead>
                                        <tr>
                                            <th>Location</th>
                                            <th>Code</th>
                                            <th>Type</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody id="seoauto-locations-body">
                                        <?php foreach ($siteLocations as $index => $location) : ?>
                                            <tr class="seoauto-location-row">
                                                <td>
                                                    <select name="site_locations[<?php echo esc_attr((string) $index); ?>][location_code]" class="seoauto-location-select">
                                                        <?php foreach ($availableLocations as $option) : ?>
                                                            <option
                                                                value="<?php echo esc_attr((string) $option['code']); ?>"
                                                                data-location-name="<?php echo esc_attr((string) $option['name']); ?>"
                                                                <?php selected((int) $location['location_code'], (int) $option['code']); ?>
                                                            >
                                                                <?php echo esc_html((string) $option['label']); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <input type="hidden" name="site_locations[<?php echo esc_attr((string) $index); ?>][location_name]" value="<?php echo esc_attr((string) $location['location_name']); ?>" class="seoauto-location-name">
                                                </td>
                                                <td class="seoauto-location-code-cell"><?php echo esc_html((string) $location['location_code']); ?></td>
                                                <td>
                                                    <select name="site_locations[<?php echo esc_attr((string) $index); ?>][location_type]" class="seoauto-location-type">
                                                        <option value="primary" <?php selected((string) $location['location_type'], 'primary'); ?>>Primary</option>
                                                        <option value="secondary" <?php selected((string) $location['location_type'], 'secondary'); ?>>Secondary</option>
                                                    </select>
                                                </td>
                                                <td>
                                                    <button type="button" class="button-link-delete seoauto-remove-location">Remove</button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <div class="seoauto-button-row" style="margin-top:10px;">
                                    <button type="button" class="button" id="seoauto-add-location-row">Add Location</button>
                                </div>
                            </div>
                        </div>

                        <div class="seoauto-card-head" style="margin-top:18px;">
                            <h2 style="font-size:16px;">Content Brief Settings</h2>
                            <?php if (array_key_exists('domain_rating', $siteSeoSettings) && $siteSeoSettings['domain_rating'] !== null) : ?>
                                <span class="seoauto-muted">
                                    Domain rating: <?php echo esc_html((string) $siteSeoSettings['domain_rating']); ?>
                                    <?php if ($domainRatingCheckedAt) : ?>
                                        · Updated <?php echo esc_html(wp_date('Y-m-d H:i', $domainRatingCheckedAt)); ?>
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="seoauto-form-grid seoauto-form-grid--two">
                            <div class="seoauto-form-field">
                                <label for="seoauto-site-settings-domain-rating">Domain Rating</label>
                                <input
                                    id="seoauto-site-settings-domain-rating"
                                    type="number"
                                    min="0"
                                    max="100"
                                    name="site_settings_domain_rating"
                                    value="<?php echo esc_attr((string) ($siteSeoSettings['domain_rating'] ?? 0)); ?>"
                                >
                            </div>
                            <div class="seoauto-form-field">
                                <label for="seoauto-site-settings-domain-rating-checked-at">Last Domain Rating Sync</label>
                                <input
                                    id="seoauto-site-settings-domain-rating-checked-at"
                                    type="text"
                                    value="<?php echo esc_attr($domainRatingCheckedAt ? wp_date('Y-m-d H:i', $domainRatingCheckedAt) : 'Not synced yet'); ?>"
                                    readonly
                                    disabled
                                >
                            </div>
                        </div>

                        <div class="seoauto-form-field">
                            <label for="seoauto-site-settings-template-id">Seeded Strategy Template</label>
                            <select
                                id="seoauto-site-settings-template-id"
                                name="site_settings_template_id"
                                data-template-configs="<?php echo esc_attr(wp_json_encode(array_map(fn (array $template): array => [
                                    'id' => (int) ($template['id'] ?? 0),
                                    'min_search_volume' => (int) ($template['min_search_volume'] ?? 0),
                                    'max_search_volume' => ($template['max_search_volume'] ?? null) !== null ? (int) $template['max_search_volume'] : null,
                                    'max_keyword_difficulty' => (int) ($template['max_keyword_difficulty'] ?? 100),
                                    'preferred_keyword_type' => (string) ($template['preferred_keyword_type'] ?? ''),
                                    'content_briefs_per_run' => (int) ($template['content_briefs_per_run'] ?? 3),
                                    'prefer_low_difficulty' => !empty($template['prefer_low_difficulty']),
                                    'allow_low_volume' => !empty($template['allow_low_volume']),
                                    'selection_notes' => (string) ($template['selection_notes'] ?? ''),
                                ], $siteSettingTemplates))); ?>"
                            >
                                <option value="0">Keep current custom settings</option>
                                <?php foreach ($siteSettingTemplates as $template) : if (!is_array($template)) continue; ?>
                                    <option value="<?php echo esc_attr((string) ($template['id'] ?? 0)); ?>" <?php selected((int) ($siteSeoSettings['template_id'] ?? 0), (int) ($template['id'] ?? 0)); ?>>
                                        <?php echo esc_html((string) ($template['name'] ?? 'Template')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="seoauto-form-grid seoauto-form-grid--three">
                            <div class="seoauto-form-field">
                                <label for="seoauto-site-settings-min-search-volume">Minimum Search Volume</label>
                                <input id="seoauto-site-settings-min-search-volume" type="number" min="0" name="site_settings_min_search_volume" value="<?php echo esc_attr((string) ($siteSeoSettings['min_search_volume'] ?? 0)); ?>">
                            </div>
                            <div class="seoauto-form-field">
                                <label for="seoauto-site-settings-max-search-volume">Maximum Search Volume</label>
                                <input id="seoauto-site-settings-max-search-volume" type="number" min="0" name="site_settings_max_search_volume" value="<?php echo esc_attr(($siteSeoSettings['max_search_volume'] ?? null) === null ? '' : (string) $siteSeoSettings['max_search_volume']); ?>">
                            </div>
                            <div class="seoauto-form-field">
                                <label for="seoauto-site-settings-max-keyword-difficulty">Maximum Keyword Difficulty</label>
                                <input id="seoauto-site-settings-max-keyword-difficulty" type="number" min="0" max="100" name="site_settings_max_keyword_difficulty" value="<?php echo esc_attr((string) ($siteSeoSettings['max_keyword_difficulty'] ?? 100)); ?>">
                            </div>
                        </div>

                        <div class="seoauto-form-grid seoauto-form-grid--two">
                            <div class="seoauto-form-field">
                                <label for="seoauto-site-settings-preferred-keyword-type">Preferred Keyword Type</label>
                                <select id="seoauto-site-settings-preferred-keyword-type" name="site_settings_preferred_keyword_type">
                                    <option value="">Auto</option>
                                    <?php foreach (['informational', 'commercial', 'transactional', 'navigational'] as $keywordType) : ?>
                                        <option value="<?php echo esc_attr($keywordType); ?>" <?php selected((string) ($siteSeoSettings['preferred_keyword_type'] ?? ''), $keywordType); ?>>
                                            <?php echo esc_html(ucwords($keywordType)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="seoauto-form-field">
                                <label for="seoauto-site-settings-content-briefs-per-run">Content Briefs Per Run</label>
                                <input id="seoauto-site-settings-content-briefs-per-run" type="number" min="1" max="10" name="site_settings_content_briefs_per_run" value="<?php echo esc_attr((string) ($siteSeoSettings['content_briefs_per_run'] ?? 3)); ?>">
                            </div>
                        </div>

                        <div class="seoauto-form-field">
                            <label for="seoauto-site-settings-selection-notes">Selection Notes</label>
                            <textarea id="seoauto-site-settings-selection-notes" name="site_settings_selection_notes" rows="4"><?php echo esc_textarea((string) ($siteSeoSettings['selection_notes'] ?? '')); ?></textarea>
                        </div>
                        <div class="seoauto-form-grid seoauto-form-grid--two">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                                <input type="checkbox" name="site_settings_prefer_low_difficulty" value="1" <?php checked(!empty($siteSeoSettings['prefer_low_difficulty'])); ?>>
                                <span>Prefer easier keywords first</span>
                            </label>
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                                <input type="checkbox" name="site_settings_allow_low_volume" value="1" <?php checked(!empty($siteSeoSettings['allow_low_volume'])); ?>>
                                <span>Allow low-volume opportunities</span>
                            </label>
                        </div>

                        <div class="seoauto-button-row" style="margin-top:16px;">
                            <button type="submit" class="button button-primary">Save Site Settings</button>
                            <span class="seoauto-muted">This syncs description, taste, location, and per-site topic thresholds to Laravel.</span>
                        </div>
                    </form>
                </section>

                <!-- Google Connection -->
                <section class="seoauto-card">
                    <h2>Google Integration</h2>

                    <?php if (!$isConnected) : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:16px;">
                            <?php wp_nonce_field('seoauto_start_oauth'); ?>
                            <input type="hidden" name="action" value="seoauto_start_oauth">
                            <button type="submit" class="seoauto-google-cta">
                                <svg width="18" height="18" viewBox="0 0 18 18" fill="none"><path d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844c-.209 1.125-.843 2.078-1.796 2.717v2.258h2.908c1.702-1.567 2.684-3.875 2.684-6.615z" fill="#4285F4"/><path d="M9 18c2.43 0 4.467-.806 5.956-2.18l-2.908-2.259c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332C2.438 15.983 5.482 18 9 18z" fill="#34A853"/><path d="M3.964 10.71c-.18-.54-.282-1.117-.282-1.71s.102-1.17.282-1.71V4.958H.957C.347 6.173 0 7.548 0 9s.348 2.827.957 4.042l3.007-2.332z" fill="#FBBC05"/><path d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0 5.482 0 2.438 2.017.957 4.958L3.964 7.29C4.672 5.163 6.656 3.58 9 3.58z" fill="#EA4335"/></svg>
                                Connect with Google
                            </button>
                        </form>
                        <p class="seoauto-muted" style="margin:0 0 12px;">Connect Google Search Console &amp; Analytics to enable automated SEO insights.</p>
                    <?php else : ?>
                        <div class="seoauto-kv-list" style="margin-bottom:14px;">
                            <div><span>Status</span><strong><?php echo wp_kses_post($this->renderStatusBadge('active')); ?></strong></div>
                            <div><span>Scopes</span><strong><?php echo esc_html(!empty($oauthScopes) ? implode(', ', array_map('strval', $oauthScopes)) : 'None'); ?></strong></div>
                            <div><span>Connected</span><strong><?php echo esc_html($oauthConnectedAt > 0 ? wp_date('Y-m-d H:i', $oauthConnectedAt) : '—'); ?></strong></div>
                            <?php if ($oauthError !== '') : ?><div><span>Last Error</span><strong style="color:var(--red);"><?php echo esc_html($oauthError); ?></strong></div><?php endif; ?>
                        </div>
                        <div class="seoauto-button-row">
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php wp_nonce_field('seoauto_start_oauth'); ?>
                                <input type="hidden" name="action" value="seoauto_start_oauth">
                                <button type="submit" class="button button-secondary">Reconnect Google</button>
                            </form>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="seoauto-inline-input-form">
                                <?php wp_nonce_field('seoauto_revoke_oauth'); ?>
                                <input type="hidden" name="action" value="seoauto_revoke_oauth">
                                <input type="text" name="revocation_reason" placeholder="Reason (optional)" style="height:32px;padding:0 8px;border:1px solid var(--gray-300);border-radius:4px;font-size:13px;">
                                <button type="submit" class="button seoauto-btn-danger">Disconnect</button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <hr style="border:none;border-top:1px solid var(--gray-200);margin:16px 0;">
                    <div class="seoauto-button-row">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('seoauto_health_check'); ?>
                            <input type="hidden" name="action" value="seoauto_health_check">
                            <button type="submit" class="button">Run Health Check</button>
                        </form>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('seoauto_rotate_token'); ?>
                            <input type="hidden" name="action" value="seoauto_rotate_token">
                            <button type="submit" class="button">Rotate API Token</button>
                        </form>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('seoauto_register_site'); ?>
                            <input type="hidden" name="action" value="seoauto_register_site">
                            <button type="submit" class="button">Sync Registration</button>
                        </form>
                    </div>
                </section>

                <!-- Automation Preferences -->
                <section class="seoauto-card seoauto-card--settings">
                    <h2>Automation Preferences</h2>
                    <form method="post" action="options.php">
                        <?php settings_fields('seoauto_settings'); ?>
                        <div class="seoauto-form-field">
                            <label for="seoauto_primary_seo_adapter">Primary SEO Plugin</label>
                            <select name="seoauto_primary_seo_adapter" id="seoauto_primary_seo_adapter">
                                <option value="auto" <?php selected($adapter, 'auto'); ?>>Auto Detect</option>
                                <option value="yoast" <?php selected($adapter, 'yoast'); ?>>Yoast SEO</option>
                                <option value="rankmath" <?php selected($adapter, 'rankmath'); ?>>Rank Math</option>
                                <option value="aioseo" <?php selected($adapter, 'aioseo'); ?>>AIOSEO</option>
                                <option value="core" <?php selected($adapter, 'core'); ?>>WordPress Core</option>
                            </select>
                        </div>
                        <div class="seoauto-form-field">
                            <div class="seoauto-label">Change Application Mode</div>
                            <label style="display:flex;align-items:center;gap:8px;margin-bottom:8px;cursor:pointer;">
                                <input type="radio" name="seoauto_change_application_mode" value="dangerous_auto_apply" <?php checked($mode, 'dangerous_auto_apply'); ?>>
                                <span>Apply changes automatically</span>
                            </label>
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                                <input type="radio" name="seoauto_change_application_mode" value="review_before_apply" <?php checked($mode, 'review_before_apply'); ?>>
                                <span>Review every change before applying</span>
                            </label>
                        </div>
                        <div class="seoauto-form-field">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                                <input type="checkbox" name="seoauto_debug_enabled" value="1" <?php checked((bool)get_option('seoauto_debug_enabled', false)); ?>>
                                <span>Enable debug logging</span>
                            </label>
                        </div>
                        <div class="seoauto-form-field">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                                <input type="checkbox" name="seoauto_allow_insecure_ssl" value="1" <?php checked((bool)get_option('seoauto_allow_insecure_ssl', false)); ?>>
                                <span>Allow insecure SSL <em>(dev only)</em></span>
                            </label>
                        </div>

                        <!-- FIX 4: Excluded pages — tag-chip UI -->
                        <div class="seoauto-form-field">
                            <label>Exclude Pages from Audits</label>
                            <div id="seoauto-exclusion-tag-ui" class="seoauto-excl-tag-ui">
                                <!-- Selected chips rendered by JS -->
                                <div class="seoauto-excl-chips"></div>
                                <!-- Search input -->
                                <div class="seoauto-excl-search-wrap">
                                    <input
                                        type="text"
                                        class="seoauto-excl-search"
                                        placeholder="Search and add pages…"
                                        autocomplete="off"
                                    >
                                </div>
                                <!-- Dropdown options -->
                                <div class="seoauto-excl-dropdown">
                                    <?php foreach ($allPosts as $postId) :
                                        $postTitle = get_the_title($postId);
                                        $postType  = get_post_type($postId);
                                        $typeLabel = $postType === 'page' ? 'Page' : 'Post';
                                        $isSelected = in_array($postId, $excludedIds, true);
                                    ?>
                                        <div
                                            class="seoauto-excl-option<?php echo $isSelected ? ' is-selected' : ''; ?>"
                                            data-id="<?php echo esc_attr((string)$postId); ?>"
                                            data-label="<?php echo esc_attr(strtolower($postTitle)); ?>"
                                        >
                                            <span class="seoauto-excl-checkmark"><?php echo $isSelected ? '✓' : ''; ?></span>
                                            <span class="seoauto-excl-type-tag"><?php echo esc_html($typeLabel); ?></span>
                                            <?php echo esc_html($postTitle); ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <input type="hidden" name="seoauto_excluded_change_audit_pages" id="seoauto-exclusion-hidden" value="<?php echo esc_attr($excludedRaw); ?>">
                            <div class="description" style="margin-top:6px;">Click pages to add/remove. Selected pages will not trigger audits on save or publish.</div>
                        </div>

                        <?php submit_button('Save Preferences', 'primary', 'submit', false); ?>
                    </form>
                </section>
            </div>
        </div>
        <?php
    }

    // ─── Render: Change Center ─────────────────────────────────────────────────

    public function renderLogsPage(): void
    {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');

        $notice = isset($_GET['seoauto_notice']) ? sanitize_text_field((string)$_GET['seoauto_notice']) : '';

        $statusArr     = isset($_GET['status'])      ? array_filter(array_map('sanitize_text_field', (array)$_GET['status']))      : [];
        $actionTypeArr = isset($_GET['action_type']) ? array_filter(array_map('sanitize_text_field', (array)$_GET['action_type'])) : [];
        $targetTypeArr = isset($_GET['target_type']) ? array_filter(array_map('sanitize_text_field', (array)$_GET['target_type'])) : [];
        $postIdArr     = isset($_GET['post_id'])     ? array_filter(array_map('intval',              (array)$_GET['post_id']))      : [];
        $search        = isset($_GET['q'])           ? sanitize_text_field((string)$_GET['q'])  : '';
        $page          = max(1, (int)($_GET['paged'] ?? 1));
        $perPage       = min(100, max(10, (int)($_GET['per_page'] ?? 10)));
        $offset        = ($page - 1) * $perPage;

        // FIX 1: OR logic — each filter group is OR within itself, AND between groups
        $filters = [];
        if (!empty($statusArr))     $filters['status']      = $statusArr;
        if (!empty($actionTypeArr)) $filters['action_type'] = $actionTypeArr;
        if (!empty($targetTypeArr)) $filters['target_type'] = $targetTypeArr;
        if (!empty($postIdArr))     $filters['post_ids']    = $postIdArr;
        if ($search !== '')         $filters['search']      = $search;

        $totalActions = $this->actionRepository->countActions($filters);
        $totalPages   = max(1, (int) ceil($totalActions / $perPage));
        if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

        $actions           = $this->actionRepository->listActions($filters, $perPage, $offset);
        $actionTypeOptions = $this->actionRepository->listDistinctActionTypes();
        $targetTypeOptions = $this->actionRepository->listDistinctTargetTypes();

        $siteId = (int) get_option('seoauto_site_id', 0);
        global $wpdb;
        $itemsTable = $wpdb->prefix . 'seoauto_action_items';
        $humanItems = $siteId > 0
            ? $wpdb->get_results($wpdb->prepare("SELECT * FROM {$itemsTable} WHERE site_id = %d ORDER BY updated_at DESC LIMIT 200", $siteId), ARRAY_A) // phpcs:ignore
            : [];
        if (!is_array($humanItems)) $humanItems = [];
        $openHumanItems = count(array_filter($humanItems, fn($i) => ($i['status'] ?? '') !== 'resolved'));

        $actionIdsOnPage = array_values(array_filter(array_map(
            static fn (array $row): int => (int)($row['laravel_action_id'] ?? 0),
            $actions
        ), static fn (int $id): bool => $id > 0));

        $changeLogs = $this->actionRepository->listChangeLogsForLaravelIds($actionIdsOnPage, 500, ['exclude_event_type' => 'human_action_created']);

        $groupedChangeLogs = [];
        foreach ($changeLogs as $log) {
            $lid = (int)($log['laravel_action_id'] ?? 0);
            if ($lid <= 0) continue;
            if (!isset($groupedChangeLogs[$lid])) {
                $groupedChangeLogs[$lid] = ['events' => []];
            }
            $groupedChangeLogs[$lid]['events'][] = $log;
        }

        $allPosts = get_posts(['post_type'=>['post','page'],'post_status'=>'publish','posts_per_page'=>500,'orderby'=>'title','order'=>'ASC','fields'=>'ids']);

        // FIX 1: Build label maps for JS chip filter bar
        $labelMapsJson = wp_json_encode([
            'status'      => ['received'=>'Received','queued'=>'Queued','running'=>'Running','applied'=>'Applied','failed'=>'Failed','rolled_back'=>'Rolled Back'],
            'action_type' => array_combine($actionTypeOptions, $actionTypeOptions),
            'target_type' => array_combine($targetTypeOptions, $targetTypeOptions),
            'post_id'     => array_combine(
                array_map('strval', $allPosts),
                array_map(fn($pid) => (string)get_the_title($pid), $allPosts)
            ),
        ]);
        ?>
        <div class="wrap seoauto-admin-page">
            <?php $this->renderAdminShellHeader('Change Center', 'seoauto-logs', 'Review automated SEO changes and their execution history.'); ?>
            <?php $this->renderNotice($notice); ?>

            <div class="seoauto-kpi-grid">
                <div class="seoauto-kpi-card"><span class="seoauto-kpi-label">Total Actions</span><span class="seoauto-kpi-value"><?php echo esc_html((string)$totalActions); ?></span></div>
                <div class="seoauto-kpi-card"><span class="seoauto-kpi-label">On This Page</span><span class="seoauto-kpi-value"><?php echo esc_html((string)count($actions)); ?></span></div>
                <div class="seoauto-kpi-card"><span class="seoauto-kpi-label">Human Items</span><span class="seoauto-kpi-value"><?php echo esc_html((string)count($humanItems)); ?></span></div>
                <div class="seoauto-kpi-card"><span class="seoauto-kpi-label">Open Items</span><span class="seoauto-kpi-value"><?php echo esc_html((string)$openHumanItems); ?></span></div>
            </div>

            <!-- FIX 1: Chip filter bar — data-label-maps added, hidden inputs removed from PHP -->
            <div class="seoauto-chip-filter-bar" id="seoauto-filter-bar" data-label-maps="<?php echo esc_attr($labelMapsJson); ?>">
                <form method="get" class="seoauto-filter-form" id="seoauto-filter-form">
                    <input type="hidden" name="page" value="seoauto-logs">
                    <input type="hidden" name="per_page" value="<?php echo esc_attr((string)$perPage); ?>">

                    <!-- Active filter chips row — populated by JS -->
                    <div class="seoauto-active-chips" id="seoauto-active-chips"></div>

                    <!-- Filter dropdowns row -->
                    <div class="seoauto-filter-dropdowns">
                        <?php
                        $filterDefs = [
                            ['key'=>'status',      'label'=>'Status',      'options'=> array_combine(
                                ['received','queued','running','applied','failed','rolled_back'],
                                ['Received','Queued','Running','Applied','Failed','Rolled Back']
                            )],
                            ['key'=>'action_type', 'label'=>'Action Type', 'options'=> array_combine($actionTypeOptions, $actionTypeOptions)],
                            ['key'=>'target_type', 'label'=>'Target Type', 'options'=> array_combine($targetTypeOptions, $targetTypeOptions)],
                        ];
                        foreach ($filterDefs as $fd) :
                            $activeVals = match($fd['key']) {
                                'status'      => $statusArr,
                                'action_type' => $actionTypeArr,
                                'target_type' => $targetTypeArr,
                                default       => [],
                            };
                        ?>
                        <div class="seoauto-filter-dropdown" data-filter-key="<?php echo esc_attr($fd['key']); ?>">
                            <button type="button" class="seoauto-filter-btn <?php echo !empty($activeVals) ? 'has-active' : ''; ?>">
                                <?php echo esc_html($fd['label']); ?>
                                <?php if (!empty($activeVals)) echo '<span class="seoauto-filter-count">' . count($activeVals) . '</span>'; ?>
                                <span class="seoauto-filter-chevron">▾</span>
                            </button>
                            <div class="seoauto-filter-panel" style="display:none;">
                                <div class="seoauto-filter-panel-inner">
                                    <?php foreach ($fd['options'] as $val => $label) : ?>
                                        <label class="seoauto-filter-option">
                                            <input type="checkbox"
                                                name="<?php echo esc_attr($fd['key']); ?>[]"
                                                value="<?php echo esc_attr((string)$val); ?>"
                                                <?php checked(in_array((string)$val, $activeVals, true)); ?>>
                                            <?php echo esc_html((string)$label); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <div class="seoauto-filter-panel-footer">
                                    <button type="button" class="seoauto-filter-clear-one button-link" data-filter-key="<?php echo esc_attr($fd['key']); ?>">Clear</button>
                                    <button type="button" class="button button-small button-primary seoauto-filter-apply">Apply</button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <!-- Page / Post filter -->
                        <div class="seoauto-filter-dropdown" data-filter-key="post_id">
                            <button type="button" class="seoauto-filter-btn <?php echo !empty($postIdArr) ? 'has-active' : ''; ?>">
                                Page
                                <?php if (!empty($postIdArr)) echo '<span class="seoauto-filter-count">' . count($postIdArr) . '</span>'; ?>
                                <span class="seoauto-filter-chevron">▾</span>
                            </button>
                            <div class="seoauto-filter-panel seoauto-filter-panel--wide" style="display:none;">
                                <div class="seoauto-filter-panel-search">
                                    <input type="text" placeholder="Search pages…" class="seoauto-filter-post-search" autocomplete="off">
                                </div>
                                <div class="seoauto-filter-panel-inner" style="max-height:200px;overflow-y:auto;">
                                    <?php foreach ($allPosts as $pid) :
                                        $pt = get_post_type($pid) === 'page' ? 'Page' : 'Post';
                                        $ptitle = get_the_title($pid);
                                    ?>
                                        <label class="seoauto-filter-option" data-label="<?php echo esc_attr(strtolower($ptitle)); ?>">
                                            <input type="checkbox"
                                                name="post_id[]"
                                                value="<?php echo esc_attr((string)$pid); ?>"
                                                <?php checked(in_array($pid, $postIdArr, true)); ?>>
                                            <span class="seoauto-filter-type-tag"><?php echo esc_html($pt); ?></span>
                                            <?php echo esc_html($ptitle); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <div class="seoauto-filter-panel-footer">
                                    <button type="button" class="seoauto-filter-clear-one button-link" data-filter-key="post_id">Clear</button>
                                    <button type="button" class="button button-small button-primary seoauto-filter-apply">Apply</button>
                                </div>
                            </div>
                        </div>

                        <!-- Keyword search -->
                        <div style="display:flex;gap:6px;align-items:center;margin-left:auto;">
                            <input type="text" name="q" value="<?php echo esc_attr($search); ?>" placeholder="Search keyword…" style="height:32px;padding:0 10px;border:1px solid var(--gray-300);border-radius:4px;font-size:13px;width:180px;">
                            <button class="button button-primary" type="submit">Search</button>
                            <?php if (!empty($statusArr) || !empty($actionTypeArr) || !empty($targetTypeArr) || !empty($postIdArr) || $search !== '') : ?>
                                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=seoauto-logs')); ?>">Reset</a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- FIX 1: Hidden inputs managed entirely by JS — empty on page load -->
                    <div id="seoauto-filter-hidden-inputs" class="seoauto-filter-hidden-inputs"></div>
                </form>
            </div>

            <!-- Actions Table -->
            <div class="seoauto-card" style="padding:0;overflow:hidden;">
                <div class="seoauto-table-wrap">
                <table class="wp-list-table widefat seoauto-changes-table">
                    <thead>
                        <tr>
                            <th style="min-width:200px;">Title / Target</th>
                            <th style="min-width:110px;">Action Type</th>
                            <th style="min-width:90px;">Status</th>
                            <th style="min-width:220px;">Proposed Change</th>
                            <th style="min-width:120px;">Received</th>
                            <th style="min-width:100px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($actions)) : ?>
                        <?php foreach ($actions as $row) : ?>
                            <?php
                            $laravelId      = (int)($row['laravel_action_id'] ?? 0);
                            $rowStatus      = (string)($row['status'] ?? 'received');
                            $isApplied      = $rowStatus === 'applied';
                            $actionPayload  = json_decode((string)($row['action_payload'] ?? '{}'), true);
                            $beforeSnapshot = json_decode((string)($row['before_snapshot'] ?? '{}'), true);
                            if (!is_array($actionPayload))  $actionPayload  = [];
                            if (!is_array($beforeSnapshot)) $beforeSnapshot = [];
                            $actionTitle    = $this->buildActionDisplayTitle($row, $actionPayload);
                            $editableFields = $this->buildEditableFields((string)($row['action_type'] ?? ''), $actionPayload);
                            $proposedFields = $this->buildReadOnlyFields((string)($row['action_type'] ?? ''), $actionPayload, [], []);
                            $currentFields  = $this->buildReadOnlyFields((string)($row['action_type'] ?? ''), $beforeSnapshot, [], []);
                            $hasLogs        = isset($groupedChangeLogs[$laravelId]) && !empty($groupedChangeLogs[$laravelId]['events']);
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($actionTitle); ?></strong>
                                    <?php if (!empty($row['target_url'])) : ?>
                                        <div class="seoauto-muted seoauto-truncate" style="max-width:200px;" title="<?php echo esc_attr((string)$row['target_url']); ?>">
                                            <a href="<?php echo esc_url((string)$row['target_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html((string)$row['target_url']); ?></a>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($hasLogs) : ?>
                                        <!-- FIX 2: progression toggle now uses document-level JS listener -->
                                        <button type="button"
                                            class="seoauto-progression-toggle"
                                            data-target="progression-<?php echo esc_attr((string)$laravelId); ?>"
                                            title="Show execution steps">
                                            <span class="seoauto-prog-arrow">▸</span> progression
                                        </button>
                                    <?php endif; ?>
                                </td>
                                <td><code style="font-size:11px;"><?php echo esc_html((string)($row['action_type'] ?? '')); ?></code></td>
                                <td><?php echo wp_kses_post($this->renderStatusBadge($rowStatus)); ?></td>

                                <!-- FIX 5: Edit form lives here, in the Proposed Change column -->
                                <td>
                                    <div class="seoauto-inline-edit-container">
                                        <div class="seoauto-inline-display">
                                            <?php if (!empty($proposedFields)) : ?>
                                                <?php foreach ($proposedFields as $field) : ?>
                                                    <div style="margin-bottom:4px;">
                                                        <div class="seoauto-field-label"><?php echo esc_html((string)($field['label'] ?? '')); ?></div>
                                                        <div class="seoauto-field-value"><?php echo esc_html((string)($field['value'] ?? '')); ?></div>
                                                    </div>
                                                <?php endforeach; ?>
                                                <?php if (!empty($currentFields) && $currentFields !== $proposedFields) : ?>
                                                    <button type="button" class="seoauto-toggle-current">▸ Currently applied</button>
                                                    <div class="seoauto-current-value" style="display:none;">
                                                        <div class="seoauto-field-label">Currently applied</div>
                                                        <?php foreach ($currentFields as $cf) : ?>
                                                            <div class="seoauto-field-value" style="color:var(--gray-500);"><?php echo esc_html((string)($cf['value'] ?? '')); ?></div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php else : ?>
                                                <span class="seoauto-muted">—</span>
                                            <?php endif; ?>
                                        </div>

                                        <?php if (!empty($editableFields)) : ?>
                                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                                <?php wp_nonce_field('seoauto_edit_action_payload'); ?>
                                                <input type="hidden" name="action" value="seoauto_edit_action_payload">
                                                <input type="hidden" name="action_id" value="<?php echo esc_attr((string)$laravelId); ?>">
                                                <div class="seoauto-inline-edit-actions">
                                                    <button type="button" class="button button-small" data-seoauto-edit-toggle="1">Edit</button>
                                                    <button type="submit" class="button button-small button-primary" data-seoauto-save-button="1" style="display:none;">Save</button>
                                                    <button type="button" class="button button-small" data-seoauto-cancel-button="1" style="display:none;">Cancel</button>
                                                </div>
                                                <div class="seoauto-inline-edit-fields">
                                                    <?php foreach ($editableFields as $field) : ?>
                                                        <?php
                                                        $fk = (string)($field['key']   ?? '');
                                                        $fl = (string)($field['label'] ?? $fk);
                                                        $ft = (string)($field['type']  ?? 'text');
                                                        $fv = (string)($field['value'] ?? '');
                                                        $fmin = isset($field['min_length']) ? (int)$field['min_length'] : 0;
                                                        $fmax = isset($field['max_length']) ? (int)$field['max_length'] : 0;
                                                        $fvalidation = (string)($field['validation'] ?? '');
                                                        ?>
                                                        <label><?php echo esc_html($fl); ?>
                                                            <?php if ($ft === 'textarea') : ?>
                                                                <textarea
                                                                    name="payload_fields[<?php echo esc_attr($fk); ?>]"
                                                                    rows="3"
                                                                    <?php if ($fmin > 0) : ?>data-min-length="<?php echo esc_attr((string)$fmin); ?>"<?php endif; ?>
                                                                    <?php if ($fmax > 0) : ?>data-max-length="<?php echo esc_attr((string)$fmax); ?>"<?php endif; ?>
                                                                    <?php if ($fvalidation !== '') : ?>data-validation="<?php echo esc_attr($fvalidation); ?>"<?php endif; ?>
                                                                ><?php echo esc_textarea($fv); ?></textarea>
                                                            <?php else : ?>
                                                                <input
                                                                    type="text"
                                                                    name="payload_fields[<?php echo esc_attr($fk); ?>]"
                                                                    value="<?php echo esc_attr($fv); ?>"
                                                                    <?php if ($fmin > 0) : ?>data-min-length="<?php echo esc_attr((string)$fmin); ?>"<?php endif; ?>
                                                                    <?php if ($fmax > 0) : ?>data-max-length="<?php echo esc_attr((string)$fmax); ?>"<?php endif; ?>
                                                                    <?php if ($fvalidation !== '') : ?>data-validation="<?php echo esc_attr($fvalidation); ?>"<?php endif; ?>
                                                                >
                                                            <?php endif; ?>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td class="seoauto-nowrap seoauto-muted" style="font-size:12px;"><?php echo esc_html((string)($row['received_at'] ?? '')); ?></td>

                                <!-- Actions column: only Apply/Revert, no Edit button -->
                                <td>
                                    <div class="seoauto-action-btns">
                                        <?php if (!$isApplied) : ?>
                                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                                <?php wp_nonce_field('seoauto_apply_action'); ?>
                                                <input type="hidden" name="action" value="seoauto_apply_action">
                                                <input type="hidden" name="action_id" value="<?php echo esc_attr((string)$laravelId); ?>">
                                                <button class="button button-small button-primary" type="submit">Apply</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($isApplied) : ?>
                                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                                <?php wp_nonce_field('seoauto_revert_action'); ?>
                                                <input type="hidden" name="action" value="seoauto_revert_action">
                                                <input type="hidden" name="action_id" value="<?php echo esc_attr((string)$laravelId); ?>">
                                                <button class="button button-small seoauto-btn-danger" type="submit">Revert</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php if ($hasLogs) : ?>
                                <tr id="progression-<?php echo esc_attr((string)$laravelId); ?>" class="seoauto-progression-row" style="display:none;">
                                    <td colspan="6" style="padding:0 0 0 32px;background:var(--gray-50);border-top:none;">
                                        <div class="seoauto-prog-timeline">
                                            <?php
                                            $events = array_reverse($groupedChangeLogs[$laravelId]['events']);
                                            foreach ($events as $event) :
                                                $evStatus = strtolower(trim((string)($event['status'] ?? '')));
                                                $evNote   = (string)($event['note'] ?? '');
                                                $evAt     = (string)($event['created_at'] ?? '');
                                                $showNote = $this->shouldRenderTimelineNote((string)($event['event_type']??''), $evNote);
                                            ?>
                                                <div class="seoauto-prog-step">
                                                    <div class="seoauto-prog-dot seoauto-prog-dot--<?php echo esc_attr($evStatus); ?>"></div>
                                                    <div class="seoauto-prog-info">
                                                        <?php echo wp_kses_post($this->renderStatusBadge($evStatus)); ?>
                                                        <span class="seoauto-prog-ts"><?php echo esc_html($evAt); ?></span>
                                                        <?php if ($showNote) : ?><div class="seoauto-prog-note"><?php echo esc_html($evNote); ?></div><?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="6" style="padding:32px;text-align:center;color:var(--gray-400);">No actions found. Adjust filters or wait for changes to be received.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <!-- Pagination -->
            <?php
            $baseArgs = ['page'=>'seoauto-logs','per_page'=>$perPage,'q'=>$search];
            foreach ($statusArr as $v)     $baseArgs['status'][]      = $v;
            foreach ($actionTypeArr as $v) $baseArgs['action_type'][] = $v;
            foreach ($targetTypeArr as $v) $baseArgs['target_type'][] = $v;
            foreach ($postIdArr as $v)     $baseArgs['post_id'][]     = $v;
            ?>
            <div class="seoauto-pagination">
                <div>Showing <?php echo esc_html((string)count($actions)); ?> of <?php echo esc_html((string)$totalActions); ?> (page <?php echo esc_html((string)$page); ?> / <?php echo esc_html((string)$totalPages); ?>)</div>
                <div class="seoauto-button-row">
                    <a class="button <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo esc_url(add_query_arg(array_merge($baseArgs, ['paged'=>max(1,$page-1)]), admin_url('admin.php'))); ?>">← Previous</a>
                    <a class="button <?php echo $page >= $totalPages ? 'disabled' : ''; ?>" href="<?php echo esc_url(add_query_arg(array_merge($baseArgs, ['paged'=>min($totalPages,$page+1)]), admin_url('admin.php'))); ?>">Next →</a>
                </div>
            </div>

            <!-- Delete logs -->
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:24px;">
                <?php wp_nonce_field('seoauto_delete_logs'); ?>
                <input type="hidden" name="action" value="seoauto_delete_logs">
                <button type="submit" class="button seoauto-btn-danger" onclick="return confirm('Delete all execution logs? This cannot be undone.');">Delete All Logs</button>
            </form>
        </div>
        <?php
        // NOTE: All JS for this page (chip filters, progression toggle, inline edit) is in admin.js
    }

    // ─── Render: Action Items ──────────────────────────────────────────────────

    public function renderActionItemsPage(): void
    {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');

        global $wpdb;
        $table        = $wpdb->prefix . 'seoauto_action_items';
        $actionsTable = $wpdb->prefix . 'seoauto_actions';
        $siteId       = (int) get_option('seoauto_site_id', 0);
        
        $statusArr      = isset($_GET['status'])      ? array_filter(array_map('sanitize_text_field', (array)$_GET['status']))      : [];
        $categoryArr    = isset($_GET['category'])    ? array_filter(array_map('sanitize_text_field', (array)$_GET['category']))    : [];
        $targetTypeArr  = isset($_GET['target_type']) ? array_filter(array_map('sanitize_text_field', (array)$_GET['target_type'])) : [];
        $search         = isset($_GET['q'])           ? sanitize_text_field((string)$_GET['q'])           : '';
        
        $page    = max(1, (int)($_GET['paged'] ?? 1));
        $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 10)));
        $offset  = ($page - 1) * $perPage;
        $notice  = isset($_GET['seoauto_notice']) ? sanitize_text_field((string)$_GET['seoauto_notice']) : '';

        $where  = ['1=1'];
        $params = [];
        if ($siteId > 0) { $where[] = 'i.site_id = %d'; $params[] = $siteId; } else { $where[] = '1=0'; }
        
        if (!empty($statusArr)) {
            $placeholders = implode(',', array_fill(0, count($statusArr), '%s'));
            $where[] = "i.status IN ($placeholders)";
            $params = array_merge($params, $statusArr);
        }
        
        if (!empty($categoryArr)) {
            $placeholders = implode(',', array_fill(0, count($categoryArr), '%s'));
            $where[] = "i.category IN ($placeholders)";
            $params = array_merge($params, $categoryArr);
        }
        
        if (!empty($targetTypeArr)) {
            $placeholders = implode(',', array_fill(0, count($targetTypeArr), '%s'));
            $where[] = "a.target_type IN ($placeholders)";
            $params = array_merge($params, $targetTypeArr);
        }
        
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(i.title LIKE %s OR i.details LIKE %s OR i.recommended_value LIKE %s)';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }
        
        $whereSql = implode(' AND ', $where);

        $items = $wpdb->get_results( // phpcs:ignore
            $wpdb->prepare("SELECT i.*, a.target_type, a.target_id, a.target_url, a.action_type AS linked_action_type, a.status AS linked_action_status FROM {$table} i LEFT JOIN {$actionsTable} a ON a.laravel_action_id = i.laravel_action_id WHERE {$whereSql} ORDER BY i.updated_at DESC LIMIT %d OFFSET %d",
                ...array_merge($params, [$perPage, $offset]))
        );
        if (!is_array($items)) $items = [];

        $total      = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} i LEFT JOIN {$actionsTable} a ON a.laravel_action_id = i.laravel_action_id WHERE {$whereSql}", ...$params)); // phpcs:ignore
        $totalPages = max(1, (int)ceil($total / $perPage));

        $categoryOptions   = $siteId > 0 ? (array)$wpdb->get_col($wpdb->prepare("SELECT DISTINCT category FROM {$table} WHERE site_id = %d ORDER BY category ASC", $siteId)) : []; // phpcs:ignore
        $targetTypeOptions = $siteId > 0 ? (array)$wpdb->get_col($wpdb->prepare("SELECT DISTINCT a.target_type FROM {$table} i INNER JOIN {$actionsTable} a ON a.laravel_action_id = i.laravel_action_id WHERE i.site_id = %d AND a.target_type <> '' ORDER BY a.target_type ASC", $siteId)) : []; // phpcs:ignore
        
        $labelMapsJson = wp_json_encode([
            'status'      => ['open'=>'Open','in_progress'=>'In Progress','resolved'=>'Resolved'],
            'category'    => array_combine($categoryOptions, $categoryOptions),
            'target_type' => array_combine($targetTypeOptions, $targetTypeOptions),
        ]);
        ?>
        <div class="wrap seoauto-admin-page">
            <?php $this->renderAdminShellHeader('Action Items', 'seoauto-action-items', 'Track tasks that need your manual attention.'); ?>
            <?php $this->renderNotice($notice); ?>
            <?php if ($siteId <= 0) : ?>
                <div class="notice notice-warning"><p>Site not yet registered. Action items will appear here once registration is complete.</p></div>
            <?php endif; ?>

            <!-- Chip Filter Bar -->
            <div class="seoauto-chip-filter-bar" id="seoauto-items-filter-bar" data-label-maps="<?php echo esc_attr($labelMapsJson); ?>">
                <form method="get" class="seoauto-filter-form" id="seoauto-items-filter-form">
                    <input type="hidden" name="page" value="seoauto-action-items">
                    <input type="hidden" name="per_page" value="<?php echo esc_attr((string)$perPage); ?>">

                    <!-- Active filter chips row -->
                    <div class="seoauto-active-chips" id="seoauto-items-active-chips"></div>

                    <!-- Filter dropdowns row -->
                    <div class="seoauto-filter-dropdowns">
                        <?php
                        $filterDefs = [
                            ['key'=>'status',      'label'=>'Status',      'options'=> ['open'=>'Open','in_progress'=>'In Progress','resolved'=>'Resolved']],
                            ['key'=>'category',    'label'=>'Category',    'options'=> array_combine($categoryOptions, $categoryOptions)],
                            ['key'=>'target_type', 'label'=>'Target Type', 'options'=> array_combine($targetTypeOptions, $targetTypeOptions)],
                        ];
                        foreach ($filterDefs as $fd) :
                            $activeVals = match($fd['key']) {
                                'status'      => $statusArr,
                                'category'    => $categoryArr,
                                'target_type' => $targetTypeArr,
                                default       => [],
                            };
                        ?>
                        <div class="seoauto-filter-dropdown" data-filter-key="<?php echo esc_attr($fd['key']); ?>">
                            <button type="button" class="seoauto-filter-btn <?php echo !empty($activeVals) ? 'has-active' : ''; ?>">
                                <?php echo esc_html($fd['label']); ?>
                                <?php if (!empty($activeVals)) echo '<span class="seoauto-filter-count">' . count($activeVals) . '</span>'; ?>
                                <span class="seoauto-filter-chevron">▾</span>
                            </button>
                            <div class="seoauto-filter-panel" style="display:none;">
                                <div class="seoauto-filter-panel-inner">
                                    <?php foreach ($fd['options'] as $val => $label) : ?>
                                        <label class="seoauto-filter-option">
                                            <input type="checkbox"
                                                name="<?php echo esc_attr($fd['key']); ?>[]"
                                                value="<?php echo esc_attr((string)$val); ?>"
                                                <?php checked(in_array((string)$val, $activeVals, true)); ?>>
                                            <?php echo esc_html((string)$label); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <div class="seoauto-filter-panel-footer">
                                    <button type="button" class="seoauto-filter-clear-one button-link" data-filter-key="<?php echo esc_attr($fd['key']); ?>">Clear</button>
                                    <button type="button" class="button button-small button-primary seoauto-filter-apply">Apply</button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <!-- Keyword search -->
                        <div style="display:flex;gap:6px;align-items:center;margin-left:auto;">
                            <input type="text" name="q" value="<?php echo esc_attr($search); ?>" placeholder="Search keyword…" style="height:32px;padding:0 10px;border:1px solid var(--gray-300);border-radius:4px;font-size:13px;width:180px;">
                            <button class="button button-primary" type="submit">Search</button>
                            <?php if (!empty($statusArr) || !empty($categoryArr) || !empty($targetTypeArr) || $search !== '') : ?>
                                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=seoauto-action-items')); ?>">Reset</a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Hidden inputs managed by JS -->
                    <div id="seoauto-items-filter-hidden-inputs" class="seoauto-filter-hidden-inputs"></div>
                </form>
            </div>

            <div class="seoauto-card" style="padding:0;overflow:hidden;">
                <div class="seoauto-table-wrap">
                <table class="wp-list-table widefat seoauto-items-table">
                    <thead>
                        <tr><th>Title</th><th>Category</th><th>Status</th><th>Target</th><th>Details</th><th>Update</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($items)) : ?>
                        <?php foreach ($items as $item) : ?>
                            <?php
                            $laravelId         = (int)($item->laravel_action_id ?? 0);
                            $targetType        = (string)($item->target_type ?? '');
                            $targetId          = (string)($item->target_id ?? '');
                            $targetUrl         = (string)($item->target_url ?? '');
                            $linkedActionType  = (string)($item->linked_action_type ?? '');
                            $linkedActionStatus= (string)($item->linked_action_status ?? '');
                            $targetLabel = '';
                            if ($targetType === 'post' && ctype_digit($targetId)) {
                                $pt = get_the_title((int)$targetId);
                                if (is_string($pt) && trim($pt) !== '') $targetLabel = trim($pt);
                            }
                            if ($targetLabel === '' && $targetUrl !== '') $targetLabel = $targetUrl;
                            if ($targetLabel === '' && $targetId !== '') $targetLabel = "{$targetType}:{$targetId}";
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html((string)$item->title); ?></strong></td>
                                <td><?php echo esc_html((string)$item->category); ?></td>
                                <td><?php echo wp_kses_post($this->renderStatusBadge((string)$item->status)); ?></td>
                                <td>
                                    <?php if ($targetLabel !== '') : ?><div><?php echo esc_html($targetLabel); ?></div><?php endif; ?>
                                    <?php if ($targetUrl !== '') : ?><a href="<?php echo esc_url($targetUrl); ?>" target="_blank" rel="noopener noreferrer" class="seoauto-muted" style="font-size:12px;">↗ View</a><?php endif; ?>
                                </td>
                                <td class="seoauto-muted"><?php echo esc_html((string)$item->details); ?></td>
                                <td>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:6px;align-items:center;">
                                        <?php wp_nonce_field('seoauto_update_action_item'); ?>
                                        <input type="hidden" name="action" value="seoauto_update_action_item">
                                        <input type="hidden" name="item_id" value="<?php echo esc_attr((string)$item->id); ?>">
                                        <select name="status" style="height:28px;font-size:12px;border:1px solid var(--gray-300);border-radius:4px;padding:0 6px;">
                                            <option value="open" <?php selected((string)$item->status,'open'); ?>>Open</option>
                                            <option value="in_progress" <?php selected((string)$item->status,'in_progress'); ?>>In Progress</option>
                                            <option value="resolved" <?php selected((string)$item->status,'resolved'); ?>>Resolved</option>
                                        </select>
                                        <button class="button button-small" type="submit">Save</button>
                                    </form>
                                </td>
                                <td>
                                    <div class="seoauto-action-btns">
                                        <?php if ($targetType === 'post' && ctype_digit($targetId)) : ?>
                                            <a class="button button-small" href="<?php echo esc_url(admin_url('post.php?post='.(int)$targetId.'&action=edit')); ?>">Edit Post</a>
                                        <?php endif; ?>
                                        <?php if ($laravelId > 0 && $linkedActionType !== '' && $linkedActionType !== 'human-action-required') : ?>
                                            <?php if ($linkedActionStatus !== 'applied') : ?>
                                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                                    <?php wp_nonce_field('seoauto_apply_action'); ?>
                                                    <input type="hidden" name="action" value="seoauto_apply_action">
                                                    <input type="hidden" name="action_id" value="<?php echo esc_attr((string)$laravelId); ?>">
                                                    <input type="hidden" name="return_page" value="seoauto-action-items">
                                                    <button class="button button-small button-primary" type="submit">Apply</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ($linkedActionStatus === 'applied') : ?>
                                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                                    <?php wp_nonce_field('seoauto_revert_action'); ?>
                                                    <input type="hidden" name="action" value="seoauto_revert_action">
                                                    <input type="hidden" name="action_id" value="<?php echo esc_attr((string)$laravelId); ?>">
                                                    <input type="hidden" name="return_page" value="seoauto-action-items">
                                                    <button class="button button-small seoauto-btn-danger" type="submit">Revert</button>
                                                </form>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="7">
                            <div class="seoauto-debug-empty">
                                <strong>No action items found</strong>
                                <p>Action items will appear here when your site needs manual attention.</p>
                            </div>
                        </td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <div class="seoauto-pagination">
                <div><?php echo esc_html((string)$total); ?> total items</div>
                <div>
                    <?php echo wp_kses_post(paginate_links(['base' => add_query_arg('paged','%#%'), 'format' => '', 'current' => $page, 'total' => $totalPages])); ?>
                </div>
            </div>
        </div>
        <?php
    }

    // ─── Render: Content Briefs ───────────────────────────────────────────────

    public function renderBriefsPage(): void
    {
        if (!current_user_can('edit_posts')) wp_die('Unauthorized');

        $notice = isset($_GET['seoauto_notice']) ? sanitize_text_field((string)$_GET['seoauto_notice']) : '';
        $this->briefSyncer->sync();

        global $wpdb;
        $table = $wpdb->prefix . 'seoauto_briefs';
        $articleStatusArr = isset($_GET['article_status']) ? array_filter(array_map('sanitize_text_field', (array) $_GET['article_status'])) : [];
        $assignmentStatusArr = isset($_GET['assignment_status']) ? array_filter(array_map('sanitize_text_field', (array) $_GET['assignment_status'])) : [];
        $keywordTypeArr = isset($_GET['keyword_type']) ? array_filter(array_map('sanitize_text_field', (array) $_GET['keyword_type'])) : [];
        $searchIntentArr = isset($_GET['search_intent']) ? array_filter(array_map('sanitize_text_field', (array) $_GET['search_intent'])) : [];
        $minSearchVolume = isset($_GET['search_volume_min']) && $_GET['search_volume_min'] !== '' ? max(0, (int) $_GET['search_volume_min']) : null;
        $maxSearchVolume = isset($_GET['search_volume_max']) && $_GET['search_volume_max'] !== '' ? max(0, (int) $_GET['search_volume_max']) : null;
        $minKeywordDifficulty = isset($_GET['keyword_difficulty_min']) && $_GET['keyword_difficulty_min'] !== '' ? max(0, min(100, (int) $_GET['keyword_difficulty_min'])) : null;
        $maxKeywordDifficulty = isset($_GET['keyword_difficulty_max']) && $_GET['keyword_difficulty_max'] !== '' ? max(0, min(100, (int) $_GET['keyword_difficulty_max'])) : null;
        $search = isset($_GET['q']) ? sanitize_text_field((string) $_GET['q']) : '';

        $where = ['1=1'];
        $params = [];
        if (!empty($articleStatusArr)) {
            $where[] = 'article_status IN (' . implode(',', array_fill(0, count($articleStatusArr), '%s')) . ')';
            $params = array_merge($params, $articleStatusArr);
        }
        if (!empty($assignmentStatusArr)) {
            $where[] = 'assignment_status IN (' . implode(',', array_fill(0, count($assignmentStatusArr), '%s')) . ')';
            $params = array_merge($params, $assignmentStatusArr);
        }
        if (!empty($keywordTypeArr)) {
            $where[] = 'keyword_type IN (' . implode(',', array_fill(0, count($keywordTypeArr), '%s')) . ')';
            $params = array_merge($params, $keywordTypeArr);
        }
        if (!empty($searchIntentArr)) {
            $where[] = 'search_intent IN (' . implode(',', array_fill(0, count($searchIntentArr), '%s')) . ')';
            $params = array_merge($params, $searchIntentArr);
        }
        if ($minSearchVolume !== null) {
            $where[] = 'search_volume >= %d';
            $params[] = $minSearchVolume;
        }
        if ($maxSearchVolume !== null) {
            $where[] = 'search_volume <= %d';
            $params[] = $maxSearchVolume;
        }
        if ($minKeywordDifficulty !== null) {
            $where[] = 'keyword_difficulty >= %d';
            $params[] = $minKeywordDifficulty;
        }
        if ($maxKeywordDifficulty !== null) {
            $where[] = 'keyword_difficulty <= %d';
            $params[] = $maxKeywordDifficulty;
        }
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(brief_title LIKE %s OR focus_keyword LIKE %s OR strategy_template_name LIKE %s OR primary_subreddit LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $whereSql = implode(' AND ', $where);
        $query = "SELECT * FROM {$table} WHERE {$whereSql} ORDER BY synced_at DESC LIMIT 100";
        $rows = !empty($params)
            ? $wpdb->get_results($wpdb->prepare($query, ...$params))
            : $wpdb->get_results($query); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        if (!is_array($rows)) $rows = [];

        $articleStatusOptions = (array) $wpdb->get_col("SELECT DISTINCT article_status FROM {$table} WHERE article_status <> '' ORDER BY article_status ASC"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $assignmentStatusOptions = (array) $wpdb->get_col("SELECT DISTINCT assignment_status FROM {$table} WHERE assignment_status <> '' ORDER BY assignment_status ASC"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $keywordTypeOptions = (array) $wpdb->get_col("SELECT DISTINCT keyword_type FROM {$table} WHERE keyword_type <> '' ORDER BY keyword_type ASC"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $searchIntentOptions = (array) $wpdb->get_col("SELECT DISTINCT search_intent FROM {$table} WHERE search_intent <> '' ORDER BY search_intent ASC"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

        $allPosts = get_posts([
            'post_type' => ['post', 'page'],
            'post_status' => ['publish', 'draft', 'future', 'pending', 'private'],
            'posts_per_page' => 500,
            'orderby' => 'title',
            'order' => 'ASC',
            'fields' => 'ids',
        ]);

        $labelMapsJson = wp_json_encode([
            'article_status' => array_combine($articleStatusOptions, array_map('ucfirst', $articleStatusOptions)),
            'assignment_status' => array_combine($assignmentStatusOptions, array_map('ucfirst', $assignmentStatusOptions)),
            'keyword_type' => array_combine($keywordTypeOptions, array_map('ucfirst', $keywordTypeOptions)),
            'search_intent' => array_combine($searchIntentOptions, array_map(static fn (string $value): string => ucwords(str_replace(['_', '-'], ' ', $value)), $searchIntentOptions)),
        ]);
        ?>
        <div class="wrap seoauto-admin-page">
            <?php $this->renderAdminShellHeader('Content Briefs', 'seoauto-briefs', 'Review synced content briefs and link them to published posts.'); ?>
            <?php $this->renderNotice($notice); ?>

            <?php if (empty($rows)) : ?>
                <div class="seoauto-card">
                    <div class="seoauto-debug-empty">
                        <strong>No content briefs yet</strong>
                        <p>Briefs will appear here once your SEO platform sends them through.</p>
                    </div>
                </div>
            <?php else : ?>
                <div class="seoauto-chip-filter-bar" id="seoauto-briefs-filter-bar" data-label-maps="<?php echo esc_attr($labelMapsJson); ?>">
                    <form method="get" class="seoauto-filter-form" id="seoauto-briefs-filter-form">
                        <input type="hidden" name="page" value="seoauto-briefs">
                        <div class="seoauto-active-chips" id="seoauto-briefs-active-chips"></div>
                        <div class="seoauto-filter-dropdowns">
                            <?php
                            $filterDefs = [
                                ['key' => 'article_status', 'label' => 'Article Status', 'options' => array_combine($articleStatusOptions, array_map('ucfirst', $articleStatusOptions))],
                                ['key' => 'assignment_status', 'label' => 'Assignment', 'options' => array_combine($assignmentStatusOptions, array_map('ucfirst', $assignmentStatusOptions))],
                                ['key' => 'keyword_type', 'label' => 'Keyword Type', 'options' => array_combine($keywordTypeOptions, array_map('ucfirst', $keywordTypeOptions))],
                                ['key' => 'search_intent', 'label' => 'Intent', 'options' => array_combine($searchIntentOptions, array_map(static fn (string $value): string => ucwords(str_replace(['_', '-'], ' ', $value)), $searchIntentOptions))],
                            ];
                            foreach ($filterDefs as $fd) :
                                $activeVals = match ($fd['key']) {
                                    'article_status' => $articleStatusArr,
                                    'assignment_status' => $assignmentStatusArr,
                                    'keyword_type' => $keywordTypeArr,
                                    'search_intent' => $searchIntentArr,
                                    default => [],
                                };
                            ?>
                            <div class="seoauto-filter-dropdown" data-filter-key="<?php echo esc_attr($fd['key']); ?>">
                                <button type="button" class="seoauto-filter-btn <?php echo !empty($activeVals) ? 'has-active' : ''; ?>">
                                    <?php echo esc_html($fd['label']); ?>
                                    <?php if (!empty($activeVals)) echo '<span class="seoauto-filter-count">' . count($activeVals) . '</span>'; ?>
                                    <span class="seoauto-filter-chevron">▾</span>
                                </button>
                                <div class="seoauto-filter-panel" style="display:none;">
                                    <div class="seoauto-filter-panel-inner">
                                        <?php foreach ($fd['options'] as $val => $label) : ?>
                                            <label class="seoauto-filter-option">
                                                <input type="checkbox" name="<?php echo esc_attr($fd['key']); ?>[]" value="<?php echo esc_attr((string) $val); ?>" <?php checked(in_array((string) $val, $activeVals, true)); ?>>
                                                <?php echo esc_html((string) $label); ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="seoauto-filter-panel-footer">
                                        <button type="button" class="seoauto-filter-clear-one button-link" data-filter-key="<?php echo esc_attr($fd['key']); ?>">Clear</button>
                                        <button type="button" class="button button-small button-primary seoauto-filter-apply">Apply</button>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>

                            <div style="display:flex;gap:6px;align-items:center;margin-left:auto;">
                                <input type="number" min="0" name="search_volume_min" value="<?php echo esc_attr($minSearchVolume === null ? '' : (string) $minSearchVolume); ?>" placeholder="Min SV" class="seoauto-filter-range-input">
                                <input type="number" min="0" name="search_volume_max" value="<?php echo esc_attr($maxSearchVolume === null ? '' : (string) $maxSearchVolume); ?>" placeholder="Max SV" class="seoauto-filter-range-input">
                                <input type="number" min="0" max="100" name="keyword_difficulty_min" value="<?php echo esc_attr($minKeywordDifficulty === null ? '' : (string) $minKeywordDifficulty); ?>" placeholder="Min KD" class="seoauto-filter-range-input">
                                <input type="number" min="0" max="100" name="keyword_difficulty_max" value="<?php echo esc_attr($maxKeywordDifficulty === null ? '' : (string) $maxKeywordDifficulty); ?>" placeholder="Max KD" class="seoauto-filter-range-input">
                                <input type="text" name="q" value="<?php echo esc_attr($search); ?>" placeholder="Search brief…" style="height:32px;padding:0 10px;border:1px solid var(--gray-300);border-radius:4px;font-size:13px;width:180px;">
                                <button class="button button-primary" type="submit">Search</button>
                                <?php if (!empty($articleStatusArr) || !empty($assignmentStatusArr) || !empty($keywordTypeArr) || !empty($searchIntentArr) || $minSearchVolume !== null || $maxSearchVolume !== null || $minKeywordDifficulty !== null || $maxKeywordDifficulty !== null || $search !== '') : ?>
                                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=seoauto-briefs')); ?>">Reset</a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div id="seoauto-briefs-filter-hidden-inputs" class="seoauto-filter-hidden-inputs"></div>
                    </form>
                </div>

                <div class="seoauto-briefs-list">
                    <?php foreach ($rows as $row) : ?>
                        <?php
                        $payload = json_decode((string)$row->payload, true);
                        if (!is_array($payload)) $payload = [];
                        $briefTitle   = (string)($payload['brief_title'] ?? 'Untitled Brief');
                        $focusKw      = (string)($payload['focus_keyword'] ?? '');
                        $searchIntent = (string)($payload['search_intent'] ?? '');
                        $briefSummary = (string)($payload['brief_summary'] ?? '');
                        $writerNotes  = (string)($payload['writer_notes'] ?? '');
                        $strategyTemplate = (string)($row->strategy_template_name ?? '');
                        $primarySubreddit = (string)($row->primary_subreddit ?? '');
                        $keywordType = (string)($row->keyword_type ?? '');
                        $searchVolume = isset($row->search_volume) ? (int) $row->search_volume : null;
                        $keywordDifficulty = isset($row->keyword_difficulty) ? (int) $row->keyword_difficulty : null;
                        $outlineItems = $this->collectInsightItemsByType($payload, 'outline-item', 10);
                        $painPoints   = $this->collectInsightItemsByType($payload, 'pain-point', 6);
                        $contentIdeas = $this->collectInsightItemsByType($payload, 'content-idea', 6);
                        $threads = [];
                        foreach ((array)($payload['threads'] ?? []) as $tr) {
                            if (!is_array($tr)) continue;
                            $ttl = trim((string)($tr['thread_title'] ?? ''));
                            $sub = trim((string)($tr['subreddit'] ?? ''));
                            if ($ttl === '') continue;
                            $threads[] = $sub !== '' ? "{$ttl} ({$sub})" : $ttl;
                            if (count($threads) >= 5) break;
                        }
                        ?>
                        <div class="seoauto-brief-card">
                            <div class="seoauto-brief-header">
                                <div>
                                    <h3 class="seoauto-brief-title"><?php echo esc_html($briefTitle); ?></h3>
                                    <div class="seoauto-brief-meta">
                                        <?php if ($focusKw !== '') : ?><span>🔑 <?php echo esc_html($focusKw); ?></span><?php endif; ?>
                                        <?php if ($searchIntent !== '') : ?><span>🎯 <?php echo esc_html($searchIntent); ?></span><?php endif; ?>
                                        <?php if ($keywordType !== '') : ?><span>Type: <?php echo esc_html(ucfirst($keywordType)); ?></span><?php endif; ?>
                                        <?php if ($strategyTemplate !== '') : ?><span>Template: <?php echo esc_html($strategyTemplate); ?></span><?php endif; ?>
                                        <?php if ($primarySubreddit !== '') : ?><span>r/<?php echo esc_html($primarySubreddit); ?></span><?php endif; ?>
                                        <?php if ($searchVolume !== null) : ?><span>SV: <?php echo esc_html((string) $searchVolume); ?></span><?php endif; ?>
                                        <?php if ($keywordDifficulty !== null) : ?><span>KD: <?php echo esc_html((string) $keywordDifficulty); ?></span><?php endif; ?>
                                        <span><?php echo esc_html(ucfirst((string)$row->article_status)); ?> · <?php echo esc_html(ucfirst((string)$row->assignment_status)); ?></span>
                                    </div>
                                </div>
                                <?php echo wp_kses_post($this->renderStatusBadge((string)$row->article_status)); ?>
                            </div>

                            <div class="seoauto-brief-body">
                                <?php if ($briefSummary !== '' || $writerNotes !== '') : ?>
                                <div class="seoauto-brief-col">
                                    <?php if ($briefSummary !== '') : ?>
                                        <h4>Brief Summary</h4>
                                        <p><?php echo esc_html($briefSummary); ?></p>
                                    <?php endif; ?>
                                    <?php if ($writerNotes !== '') : ?>
                                        <h4 style="margin-top:14px;">Writer Notes</h4>
                                        <p><?php echo esc_html($writerNotes); ?></p>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>

                                <?php if (!empty($outlineItems)) : ?>
                                <div class="seoauto-brief-col">
                                    <h4>Recommended Outline</h4>
                                    <ul class="seoauto-brief-ul">
                                        <?php foreach ($outlineItems as $item) : ?><li><?php echo esc_html($item); ?></li><?php endforeach; ?>
                                    </ul>
                                </div>
                                <?php endif; ?>

                                <?php if (!empty($painPoints) || !empty($contentIdeas)) : ?>
                                <div class="seoauto-brief-col">
                                    <?php if (!empty($painPoints)) : ?>
                                        <h4>Reader Pain Points</h4>
                                        <ul class="seoauto-brief-ul">
                                            <?php foreach ($painPoints as $item) : ?><li><?php echo esc_html($item); ?></li><?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                    <?php if (!empty($contentIdeas)) : ?>
                                        <h4 style="margin-top:14px;">Content Angles</h4>
                                        <ul class="seoauto-brief-ul">
                                            <?php foreach ($contentIdeas as $item) : ?><li><?php echo esc_html($item); ?></li><?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>

                                <?php if (!empty($threads)) : ?>
                                <div class="seoauto-brief-col-full">
                                    <h4>Source Threads</h4>
                                    <ul class="seoauto-brief-ul" style="columns:2;gap:16px;">
                                        <?php foreach ($threads as $t) : ?><li><?php echo esc_html($t); ?></li><?php endforeach; ?>
                                    </ul>
                                </div>
                                <?php endif; ?>
                            </div>

                            <div class="seoauto-brief-footer">
                                <div class="seoauto-brief-linked">
                                    <?php if (!empty($row->linked_wp_post_id)) : ?>
                                        Linked to: <a href="<?php echo esc_url((string)get_edit_post_link((int)$row->linked_wp_post_id)); ?>"><?php echo esc_html((string) ($row->linked_wp_post_title ?: get_the_title((int)$row->linked_wp_post_id))); ?></a>
                                    <?php else : ?>
                                        <span class="seoauto-muted">Not linked to a post yet</span>
                                    <?php endif; ?>
                                </div>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="seoauto-brief-link-form">
                                    <?php wp_nonce_field('seoauto_link_brief'); ?>
                                    <input type="hidden" name="action" value="seoauto_link_brief">
                                    <input type="hidden" name="brief_id" value="<?php echo esc_attr((string)$row->laravel_content_brief_id); ?>">
                                    <div class="seoauto-post-picker" data-hidden-input-name="wp_post_id">
                                        <input type="hidden" name="wp_post_id" value="<?php echo esc_attr((string) ($row->linked_wp_post_id ?? '')); ?>">
                                        <div class="seoauto-post-picker-display"><?php echo !empty($row->linked_wp_post_id) ? esc_html((string) ($row->linked_wp_post_title ?: get_the_title((int) $row->linked_wp_post_id))) : 'Select a post or page'; ?></div>
                                        <div class="seoauto-post-picker-dropdown">
                                            <input type="text" class="seoauto-post-picker-search" placeholder="Search posts or pages…">
                                            <div class="seoauto-post-picker-options">
                                                <?php foreach ($allPosts as $postId) :
                                                    $postTitle = (string) get_the_title($postId);
                                                    $postType = (string) get_post_type($postId);
                                                    if ($postTitle === '') continue;
                                                ?>
                                                    <button
                                                        type="button"
                                                        class="seoauto-post-picker-option"
                                                        data-post-id="<?php echo esc_attr((string) $postId); ?>"
                                                        data-post-title="<?php echo esc_attr($postTitle); ?>"
                                                        data-post-type="<?php echo esc_attr($postType); ?>"
                                                    >
                                                        <span class="seoauto-excl-type-tag"><?php echo esc_html(ucfirst($postType)); ?></span>
                                                        <?php echo esc_html($postTitle); ?>
                                                    </button>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <select name="article_status">
                                        <option value="drafted">Drafted</option>
                                        <option value="published">Published</option>
                                    </select>
                                    <button class="button button-primary" type="submit">Link Post</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // ─── Render: Schedules ────────────────────────────────────────────────────

    public function renderSchedulesPage(): void
    {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');

        $notice  = isset($_GET['seoauto_notice']) ? sanitize_text_field((string)$_GET['seoauto_notice']) : '';
        $tasks   = [];
        $scheduled = [];
        $remoteErrors = [];

        try {
            $res   = $this->client->listTasksFast();
            $tasks = isset($res['tasks']) && is_array($res['tasks']) ? $res['tasks'] : [];
        } catch (\Throwable $e) {
            $remoteErrors[] = $e->getMessage();
            $this->logger->warning('admin_tasks_fetch_failed', ['error' => $e->getMessage()], 'admin');
        }

        try {
            $res       = $this->client->listScheduledTasksFast(['limit' => 50]);
            $scheduled = isset($res['scheduled_tasks']) && is_array($res['scheduled_tasks']) ? $res['scheduled_tasks'] : [];
        } catch (\Throwable $e) {
            $remoteErrors[] = $e->getMessage();
            $this->logger->warning('admin_scheduled_runs_fetch_failed', ['error' => $e->getMessage()], 'admin');
        }
        ?>
        <div class="wrap seoauto-admin-page">
            <?php $this->renderAdminShellHeader('Schedules', 'seoauto-schedules', 'Manage background task configuration and timing.'); ?>
            <?php $this->renderNotice($notice); ?>
            <?php if (!empty($remoteErrors)) : ?>
                <div class="notice notice-warning"><p>Could not load some schedule data: <?php echo esc_html(implode(' | ', $remoteErrors)); ?></p></div>
            <?php endif; ?>

            <?php
            $scheduledByTask = [];
            foreach ($scheduled as $item) {
                $tid = (int)($item['seo_execution_task_id'] ?? 0);
                if ($tid > 0) $scheduledByTask[$tid][] = $item;
            }
            ?>

            <div class="seoauto-card" style="padding:0;overflow:hidden;">
                <div class="seoauto-table-wrap">
                <table class="wp-list-table widefat seoauto-schedules-table">
                    <thead>
                        <tr>
                            <th>Task</th>
                            <th>Category</th>
                            <th>Frequency</th>
                            <th>Status</th>
                            <th>Next / Last Run</th>
                            <th>Configure</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($tasks)) : ?>
                        <?php foreach ($tasks as $task) : ?>
                            <?php
                            $taskId    = isset($task['seo_execution_task_id']) ? (int)$task['seo_execution_task_id'] : 0;
                            $isEnabled = !empty($task['is_enabled']);
                            $delay     = isset($task['delay_minutes']) ? (int)$task['delay_minutes'] : 0;
                            $taskRuns  = $scheduledByTask[$taskId] ?? [];
                            $latestRun = !empty($taskRuns) ? $taskRuns[0] : null;
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html((string)($task['name'] ?? '')); ?></strong></td>
                                <td><?php echo esc_html((string)($task['category'] ?? '')); ?></td>
                                <td><?php echo esc_html((string)($task['frequency'] ?? '—')); ?></td>
                                <td><?php echo $isEnabled ? '<span class="seoauto-badge seoauto-status-applied">Enabled</span>' : '<span class="seoauto-badge seoauto-status-received">Disabled</span>'; ?></td>
                                <td class="seoauto-mono seoauto-muted" style="font-size:12px;">
                                    <?php if ($latestRun) : ?>
                                        <?php echo wp_kses_post($this->renderStatusBadge((string)($latestRun['status'] ?? ''))); ?>
                                        <div style="margin-top:3px;"><?php echo esc_html((string)($latestRun['scheduled_for'] ?? '')); ?></div>
                                    <?php else : ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($taskId > 0) : ?>
                                        <div class="seoauto-task-forms">
                                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="seoauto-task-update-form">
                                                <?php wp_nonce_field('seoauto_update_task'); ?>
                                                <input type="hidden" name="action" value="seoauto_update_task">
                                                <input type="hidden" name="task_id" value="<?php echo esc_attr((string)$taskId); ?>">
                                                <label><input type="checkbox" name="is_enabled" value="1" <?php checked($isEnabled); ?>> Enabled</label>
                                                <label>Delay: <input type="number" min="0" name="delay_minutes" value="<?php echo esc_attr((string)$delay); ?>" style="width:60px;"> min</label>
                                                <button type="submit" class="button button-small">Save</button>
                                            </form>
                                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="seoauto-task-schedule-form">
                                                <?php wp_nonce_field('seoauto_schedule_task'); ?>
                                                <input type="hidden" name="action" value="seoauto_schedule_task">
                                                <input type="hidden" name="task_id" value="<?php echo esc_attr((string)$taskId); ?>">
                                                <input type="datetime-local" name="scheduled_for">
                                                <button type="submit" class="button button-small button-primary">Schedule Run</button>
                                            </form>
                                        </div>
                                    <?php else : ?>—<?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="6"><div class="seoauto-debug-empty"><strong>No tasks found</strong><p>Tasks will appear here once your site is registered and connected.</p></div></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
        <?php
    }

    // ─── Render: Debug Logs ───────────────────────────────────────────────────

    public function renderLocalErrorsPage(): void
    {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');

        global $wpdb;
        $table = $wpdb->prefix . 'seoauto_logs';

        $notice       = isset($_GET['seoauto_notice'])  ? sanitize_text_field((string)$_GET['seoauto_notice'])  : '';
        $deletedCount = isset($_GET['deleted_count'])   ? max(0,(int)$_GET['deleted_count'])                    : 0;
        
        $severityArr  = isset($_GET['severity'])        ? array_filter(array_map('sanitize_text_field', (array)$_GET['severity'])) : [];
        $sourceArr    = isset($_GET['source'])          ? array_filter(array_map('sanitize_text_field', (array)$_GET['source']))   : [];
        $dateFrom     = isset($_GET['date_from'])       ? sanitize_text_field((string)$_GET['date_from'])       : '';
        $dateTo       = isset($_GET['date_to'])         ? sanitize_text_field((string)$_GET['date_to'])         : '';
        $search       = isset($_GET['q'])               ? sanitize_text_field((string)$_GET['q'])               : '';
        
        $page         = max(1, (int)($_GET['paged'] ?? 1));
        $perPage      = 50;
        $offset       = ($page - 1) * $perPage;

        $where = ['1=1']; $params = [];
        
        if (!empty($severityArr)) {
            $placeholders = implode(',', array_fill(0, count($severityArr), '%s'));
            $where[] = "severity IN ($placeholders)";
            $params = array_merge($params, $severityArr);
        } else {
            $where[] = "severity IN ('warning','error')";
        }

        $validSources = ['inbound','outbound','executor','admin'];
        if (!empty($sourceArr)) {
            $filtered = array_values(array_filter($sourceArr, fn($s) => in_array($s, $validSources, true)));
            if (!empty($filtered)) {
                $placeholders = implode(',', array_fill(0, count($filtered), '%s'));
                $where[] = "source IN ($placeholders)";
                $params = array_merge($params, $filtered);
            }
        }
        
        if ($dateFrom !== '') { $where[] = 'created_at >= %s'; $params[] = $dateFrom.' 00:00:00'; }
        if ($dateTo !== '')   { $where[] = 'created_at <= %s'; $params[] = $dateTo.' 23:59:59'; }
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(event_name LIKE %s OR error_message LIKE %s OR entity_type LIKE %s)';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $whereSql = implode(' AND ', $where);
        $rows  = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE {$whereSql} ORDER BY created_at DESC LIMIT %d OFFSET %d", ...array_merge($params,[$perPage,$offset]))); // phpcs:ignore
        $total = (int)$wpdb->get_var(empty($params) ? "SELECT COUNT(*) FROM {$table} WHERE {$whereSql}" : $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$whereSql}", ...$params)); // phpcs:ignore
        $totalPages = max(1,(int)ceil($total / $perPage));

        $labelMapsJson = wp_json_encode([
            'severity' => ['error'=>'Error','warning'=>'Warning'],
            'source'   => ['inbound'=>'Inbound','outbound'=>'Outbound','executor'=>'Executor','admin'=>'Admin'],
        ]);
        ?>
        <div class="wrap seoauto-admin-page">
            <?php $this->renderAdminShellHeader('Debug Logs', 'seoauto-local-errors', 'Inspect plugin warnings and errors.'); ?>
            <?php $this->renderNotice($notice); ?>

            <!-- Toolbar -->
            <div class="seoauto-button-row" style="margin-bottom:14px;">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('seoauto_delete_local_errors'); ?>
                    <input type="hidden" name="action" value="seoauto_delete_local_errors">
                    <input type="hidden" name="severity" value="all">
                    <button type="submit" class="button seoauto-btn-danger" onclick="return confirm('Delete matching log entries?');">Clear Logs</button>
                </form>
            </div>

            <!-- Chip Filter Bar -->
            <div class="seoauto-chip-filter-bar" id="seoauto-debug-filter-bar" data-label-maps="<?php echo esc_attr($labelMapsJson); ?>">
                <form method="get" class="seoauto-filter-form" id="seoauto-debug-filter-form">
                    <input type="hidden" name="page" value="seoauto-local-errors">

                    <!-- Active filter chips row -->
                    <div class="seoauto-active-chips" id="seoauto-debug-active-chips"></div>

                    <!-- Filter dropdowns row -->
                    <div class="seoauto-filter-dropdowns">
                        <?php
                        $filterDefs = [
                            ['key'=>'severity', 'label'=>'Severity', 'options'=> ['error'=>'Error','warning'=>'Warning']],
                            ['key'=>'source',   'label'=>'Source',   'options'=> ['inbound'=>'Inbound','outbound'=>'Outbound','executor'=>'Executor','admin'=>'Admin']],
                        ];
                        foreach ($filterDefs as $fd) :
                            $activeVals = match($fd['key']) {
                                'severity' => $severityArr,
                                'source'   => $sourceArr,
                                default    => [],
                            };
                        ?>
                        <div class="seoauto-filter-dropdown" data-filter-key="<?php echo esc_attr($fd['key']); ?>">
                            <button type="button" class="seoauto-filter-btn <?php echo !empty($activeVals) ? 'has-active' : ''; ?>">
                                <?php echo esc_html($fd['label']); ?>
                                <?php if (!empty($activeVals)) echo '<span class="seoauto-filter-count">' . count($activeVals) . '</span>'; ?>
                                <span class="seoauto-filter-chevron">▾</span>
                            </button>
                            <div class="seoauto-filter-panel" style="display:none;">
                                <div class="seoauto-filter-panel-inner">
                                    <?php foreach ($fd['options'] as $val => $label) : ?>
                                        <label class="seoauto-filter-option">
                                            <input type="checkbox"
                                                name="<?php echo esc_attr($fd['key']); ?>[]"
                                                value="<?php echo esc_attr((string)$val); ?>"
                                                <?php checked(in_array((string)$val, $activeVals, true)); ?>>
                                            <?php echo esc_html((string)$label); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <div class="seoauto-filter-panel-footer">
                                    <button type="button" class="seoauto-filter-clear-one button-link" data-filter-key="<?php echo esc_attr($fd['key']); ?>">Clear</button>
                                    <button type="button" class="button button-small button-primary seoauto-filter-apply">Apply</button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <!-- Date filters -->
                        <label style="display:flex;flex-direction:column;gap:4px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.6px;color:var(--gray-600);">
                            <span>From</span>
                            <input type="date" name="date_from" value="<?php echo esc_attr($dateFrom); ?>" style="height:32px;padding:0 8px;border:1px solid var(--gray-300);border-radius:4px;font-size:13px;">
                        </label>
                        <label style="display:flex;flex-direction:column;gap:4px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.6px;color:var(--gray-600);">
                            <span>To</span>
                            <input type="date" name="date_to" value="<?php echo esc_attr($dateTo); ?>" style="height:32px;padding:0 8px;border:1px solid var(--gray-300);border-radius:4px;font-size:13px;">
                        </label>

                        <!-- Keyword search -->
                        <div style="display:flex;gap:6px;align-items:center;margin-left:auto;">
                            <input type="text" name="q" value="<?php echo esc_attr($search); ?>" placeholder="Search keyword…" style="height:32px;padding:0 10px;border:1px solid var(--gray-300);border-radius:4px;font-size:13px;width:180px;">
                            <button class="button button-primary" type="submit">Search</button>
                            <?php if (!empty($severityArr) || !empty($sourceArr) || $dateFrom !== '' || $dateTo !== '' || $search !== '') : ?>
                                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=seoauto-local-errors')); ?>">Reset</a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Hidden inputs managed by JS -->
                    <div id="seoauto-debug-filter-hidden-inputs" class="seoauto-filter-hidden-inputs"></div>
                </form>
            </div>

            <div class="seoauto-card" style="padding:0;overflow:hidden;">
                <div class="seoauto-table-wrap">
                <?php if (!empty($rows)) : ?>
                <table class="wp-list-table widefat" style="min-width:900px;">
                    <thead>
                        <tr><th>Time</th><th>Severity</th><th>Source</th><th>Event</th><th>Entity</th><th>Error</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row) : ?>
                            <tr>
                                <td class="seoauto-mono seoauto-nowrap"><?php echo esc_html(wp_date('Y-m-d H:i:s', strtotime((string)$row->created_at))); ?></td>
                                <td><span class="seoauto-severity-<?php echo esc_attr((string)$row->severity); ?>"><?php echo esc_html(strtoupper((string)$row->severity)); ?></span></td>
                                <td><?php echo esc_html((string)$row->source); ?></td>
                                <td><code><?php echo esc_html((string)$row->event_name); ?></code></td>
                                <td class="seoauto-muted"><?php echo esc_html((string)$row->entity_type.':'.(string)$row->entity_id); ?></td>
                                <td><?php echo esc_html((string)$row->error_message); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else : ?>
                    <div class="seoauto-debug-empty">
                        <strong>No log entries found</strong>
                        <p>
                            <?php if (!empty($severityArr) || !empty($sourceArr) || $dateFrom !== '' || $dateTo !== '' || $search !== '') : ?>
                                Try adjusting your filters — or this is great news, no issues detected!
                            <?php else : ?>
                                Your plugin is running clean. Errors and warnings will appear here if any occur.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>
                </div>
            </div>

            <div class="seoauto-pagination">
                <div><?php echo esc_html((string)$total); ?> total entries</div>
                <div><?php echo wp_kses_post(paginate_links(['base' => add_query_arg('paged','%#%'), 'format' => '', 'current' => $page, 'total' => $totalPages])); ?></div>
            </div>
        </div>
        <?php
    }

    // ─── Render: OAuth Callback ───────────────────────────────────────────────

    public function renderOauthCallbackPage(): void
    {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');

        if (isset($_GET['status'])) { // phpcs:ignore
            $result   = $this->oauthHandler->handleCallback($_GET); // phpcs:ignore
            $status   = sanitize_text_field((string)($result['status'] ?? 'failed'));
            $scopes   = isset($result['scopes']) && is_array($result['scopes']) ? $result['scopes'] : [];
            $error    = sanitize_text_field((string)($result['error'] ?? ''));
        } else {
            $status   = (string)get_option('seoauto_oauth_status', 'pending');
            $scopes   = (array)get_option('seoauto_oauth_scopes', []);
            $error    = (string)get_option('seoauto_oauth_last_error', '');
        }
        ?>
        <div class="wrap seoauto-admin-page">
            <?php $this->renderAdminShellHeader('Google Connection', 'seoauto', ''); ?>
            <?php if ($status === 'active') : ?>
                <div class="notice notice-success"><p>✓ Google connected successfully. You can close this page.</p></div>
            <?php elseif ($status === 'error') : ?>
                <div class="notice notice-warning"><p>Connected but a health check issue was detected. Check debug logs.</p></div>
            <?php elseif (in_array($status, ['pending','in_progress'], true)) : ?>
                <div class="notice notice-info"><p>Connection not yet completed.</p></div>
            <?php else : ?>
                <div class="notice notice-error"><p>Connection failed. <?php echo esc_html($error); ?></p></div>
            <?php endif; ?>
            <p style="margin-top:16px;">
                <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=seoauto')); ?>">← Back to Settings</a>
            </p>
        </div>
        <?php
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function renderNotice(string $notice): void
    {
        $map = [
            'register_ok'            => ['success', 'Site registration updated successfully.'],
            'register_missing_base_url' => ['warning', 'No endpoint configured. Check plugin settings.'],
            'register_failed'        => ['error',   'Site registration failed. Check debug logs.'],
            'health_ok'              => ['success', 'Health check passed — connection is working.'],
            'health_failed'          => ['warning', 'Health check failed — check your connection settings.'],
            'oauth_init_failed'      => ['error',   'Failed to start Google authorization. Ensure site is registered.'],
            'oauth_revoke_ok'        => ['success', 'Google account disconnected.'],
            'oauth_revoke_failed'    => ['error',   'Disconnect failed. Check debug logs.'],
            'rotate_ok'              => ['success', 'API token rotated successfully.'],
            'rotate_failed'          => ['error',   'Token rotation failed. Check debug logs.'],
            'profile_ok'             => ['success', 'Site profile synced.'],
            'profile_failed'         => ['error',   'Profile sync failed.'],
            'task_update_ok'         => ['success', 'Task configuration saved.'],
            'task_update_failed'     => ['error',   'Task configuration update failed.'],
            'task_schedule_ok'       => ['success', 'Task scheduled successfully.'],
            'task_schedule_failed'   => ['error',   'Task scheduling failed.'],
            'brief_link_ok'          => ['success', 'Content brief linked to post.'],
            'brief_link_failed'      => ['error',   'Failed to link brief. Check post ID.'],
            'logs_delete_ok'         => ['success', 'Execution logs cleared.'],
            'logs_delete_failed'     => ['error',   'Failed to delete logs.'],
            'local_errors_delete_ok' => ['success', 'Debug log entries cleared.'],
            'local_errors_delete_failed' => ['error', 'Failed to clear debug logs.'],
            'action_apply_requested' => ['success', 'Change queued for execution.'],
            'action_revert_ok'       => ['success', 'Change reverted successfully.'],
            'action_revert_failed'   => ['error',   'Revert failed — the change may not be reversible.'],
            'action_edit_ok'         => ['success', 'Change updated.'],
            'action_edit_ok_reapply' => ['success', 'Change updated and re-queued for application.'],
            'action_edit_validation_failed' => ['error', 'Validation failed. Please fix field values and try again.'],
            'action_edit_failed'     => ['error',   'Failed to update change.'],
            'action_item_updated'    => ['success', 'Action item updated.'],
        ];

        if (!isset($map[$notice])) return;
        [$type, $message] = $map[$notice];
        echo '<div class="notice notice-' . esc_attr($type) . '"><p>' . esc_html($message) . '</p></div>';
    }

    private function renderStatusBadge(string $status): string
    {
        $normalized = sanitize_html_class(str_replace('_', '-', strtolower(trim($status))));
        $label = ucwords(str_replace(['-','_'], ' ', $status));
        return sprintf('<span class="seoauto-badge seoauto-status-%s">%s</span>', esc_attr($normalized), esc_html($label));
    }

    private function shouldRenderTimelineNote(string $eventType, string $note): bool
    {
        if (trim($note) === '') return false;
        $systemNotes = ['action received from laravel.','action queued for execution.','action execution started.','action awaiting manual review.'];
        return !in_array(strtolower(trim($note)), $systemNotes, true);
    }

    private function resolveTimelineEventLabel(string $eventType, string $status): string
    {
        $ev = strtolower(trim($eventType));
        if ($ev === 'queued' && strtolower(trim($status)) === 'running') return 'running';
        return $ev !== '' ? $ev : (trim($status) !== '' ? $status : 'received');
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $payload */
    private function buildActionDisplayTitle(array $row, array $payload): string
    {
        $title = trim((string)($payload['title'] ?? ''));
        if ($title !== '') return $title;

        $targetType = (string)($row['target_type'] ?? '');
        $targetId   = (string)($row['target_id'] ?? '');
        $targetUrl  = (string)($row['target_url'] ?? '');
        $targetLabel = '';

        if ($targetType === 'post' && ctype_digit($targetId)) {
            $pt = get_the_title((int)$targetId);
            if (is_string($pt) && trim($pt) !== '') $targetLabel = trim($pt);
        }
        if ($targetLabel === '' && $targetUrl !== '') $targetLabel = parse_url($targetUrl, PHP_URL_PATH) ?: $targetUrl;
        if ($targetLabel === '' && $targetType !== '' && $targetId !== '') $targetLabel = "{$targetType}:{$targetId}";

        $actionLabel = ucwords(str_replace(['-','_'], ' ', (string)($row['action_type'] ?? 'action')));
        return $targetLabel !== '' ? "{$targetLabel} — {$actionLabel}" : $actionLabel;
    }

    /** @param array<string,mixed> $payload @return array<int,array{key:string,label:string,type:string,value:string}> */
    private function buildEditableFields(string $actionType, array $payload): array
    {
        return match ($actionType) {
            'add-meta-description','update-meta-description' => [[
                'key'=>'meta_description',
                'label'=>'Meta Description',
                'type'=>'textarea',
                'value'=>(string)($payload['meta_description']??$payload['recommended_meta_description']??''),
                'min_length'=>70,
                'max_length'=>160,
            ]],
            'add-alt-text' => [['key'=>'alt_text','label'=>'Image Alt Text','type'=>'text','value'=>(string)($payload['alt_text']??$payload['suggested_alt']??'')]],
            'update-title' => [['key'=>'seo_title','label'=>'SEO Title','type'=>'text','value'=>(string)($payload['seo_title']??$payload['title']??'')]],
            'set-social-tags' => [
                ['key'=>'social_tags_og_title','label'=>'OG Title','type'=>'text','value'=>(string)($payload['social_tags']['og']['title']??'')],
                ['key'=>'social_tags_og_description','label'=>'OG Description','type'=>'textarea','value'=>(string)($payload['social_tags']['og']['description']??''),'max_length'=>200],
                ['key'=>'social_tags_twitter_site','label'=>'Twitter/X Handle','type'=>'text','value'=>(string)($payload['social_tags']['twitter']['site']??''),'validation'=>'twitter_handle','max_length'=>16],
            ],
            default => [],
        };
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $before @param array<string,mixed> $after @return array<int,array{label:string,value:string}> */
    private function buildReadOnlyFields(string $actionType, array $payload, array $before, array $after): array
    {
        if (in_array($actionType, ['add-meta-description','update-meta-description'], true)) {
            return [['label'=>'Meta Description','value'=>(string)($payload['meta_description']??$payload['recommended_meta_description']??'')]];
        }
        if ($actionType === 'add-alt-text') {
            return [['label'=>'Alt Text','value'=>(string)($payload['alt_text']??$payload['suggested_alt']??'')]];
        }
        if (in_array($actionType, ['add-schema','add-schema-markup'], true)) {
            return [['label'=>'Schema Type','value'=>(string)($payload['schema_type']??'Article')]];
        }
        if ($actionType === 'set-social-tags') {
            return [
                ['label'=>'OG Title','value'=>(string)($payload['social_tags']['og']['title']??'')],
                ['label'=>'Twitter/X','value'=>(string)($payload['social_tags']['twitter']['site']??'')],
            ];
        }
        if ($actionType === 'set-post-dates') {
            return [['label'=>'Published At','value'=>(string)($payload['published_at']??'')],['label'=>'Modified At','value'=>(string)($payload['modified_at']??'')]];
        }
        if ($actionType === 'update-title') {
            return [['label'=>'SEO Title','value'=>(string)($payload['seo_title']??$payload['title']??'')]];
        }
        if ($after !== []) {
            $first = array_key_first($after);
            if (is_string($first)) { $v = $after[$first] ?? ''; return [['label'=>ucwords(str_replace(['_','-'],' ',$first)),'value'=>is_scalar($v)?(string)$v:'Updated']]; }
        }
        return [];
    }

    private function updateLocalBriefLinkState(int $briefId, int $postId, string $postUrl, string $postTitle, string $postType, string $articleStatus): void
    {
        global $wpdb;
        $wpdb->update( // phpcs:ignore
            $wpdb->prefix . 'seoauto_briefs',
            [
                'linked_wp_post_id' => $postId,
                'linked_wp_post_url' => $postUrl,
                'linked_wp_post_title' => $postTitle,
                'linked_wp_post_type' => $postType,
                'article_status' => $articleStatus,
                'assignment_status' => 'completed',
                'updated_at' => current_time('mysql'),
            ],
            ['laravel_content_brief_id'=>$briefId],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function sanitizePostedSiteSettings(array $source): array
    {
        return [
            'template_id' => isset($source['site_settings_template_id']) ? max(0, (int) $source['site_settings_template_id']) : 0,
            'provider_name' => 'dataforseo',
            'domain_rating' => isset($source['site_settings_domain_rating']) ? max(0, min(100, (int) $source['site_settings_domain_rating'])) : 0,
            'min_search_volume' => isset($source['site_settings_min_search_volume']) ? max(0, (int) $source['site_settings_min_search_volume']) : 0,
            'max_search_volume' => isset($source['site_settings_max_search_volume']) && $source['site_settings_max_search_volume'] !== ''
                ? max(0, (int) $source['site_settings_max_search_volume'])
                : null,
            'max_keyword_difficulty' => isset($source['site_settings_max_keyword_difficulty']) ? max(0, min(100, (int) $source['site_settings_max_keyword_difficulty'])) : 100,
            'preferred_keyword_type' => isset($source['site_settings_preferred_keyword_type']) ? sanitize_text_field((string) wp_unslash($source['site_settings_preferred_keyword_type'])) : '',
            'content_briefs_per_run' => isset($source['site_settings_content_briefs_per_run']) ? max(1, min(10, (int) $source['site_settings_content_briefs_per_run'])) : 3,
            'prefer_low_difficulty' => !empty($source['site_settings_prefer_low_difficulty']),
            'allow_low_volume' => !empty($source['site_settings_allow_low_volume']),
            'selection_notes' => isset($source['site_settings_selection_notes']) ? sanitize_textarea_field((string) wp_unslash($source['site_settings_selection_notes'])) : '',
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function buildRemoteSiteSettingsPayload(array $settings): array
    {
        $payload = [
            'provider_name' => 'dataforseo',
            'domain_rating' => isset($settings['domain_rating']) ? (int) $settings['domain_rating'] : 0,
            'min_search_volume' => (int) ($settings['min_search_volume'] ?? 0),
            'max_search_volume' => $settings['max_search_volume'] ?? null,
            'max_keyword_difficulty' => (int) ($settings['max_keyword_difficulty'] ?? 100),
            'preferred_keyword_type' => (string) ($settings['preferred_keyword_type'] ?? ''),
            'content_briefs_per_run' => (int) ($settings['content_briefs_per_run'] ?? 3),
            'prefer_low_difficulty' => !empty($settings['prefer_low_difficulty']),
            'allow_low_volume' => !empty($settings['allow_low_volume']),
            'selection_notes' => (string) ($settings['selection_notes'] ?? ''),
        ];

        if (!empty($settings['template_id'])) {
            $payload['template_id'] = (int) $settings['template_id'];
        }

        if ($payload['preferred_keyword_type'] === '') {
            unset($payload['preferred_keyword_type']);
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $source
     * @return list<array{location_type:string,location_code:int,location_name:string,priority:int}>
     */
    private function sanitizePostedLocations(array $source): array
    {
        $rows = isset($source['site_locations']) && is_array($source['site_locations']) ? wp_unslash($source['site_locations']) : [];
        $available = [];
        foreach ($this->getAvailableLocationOptions() as $option) {
            $available[(int) $option['code']] = (string) $option['name'];
        }

        $locations = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $locationCode = isset($row['location_code']) ? (int) $row['location_code'] : 0;
            if ($locationCode <= 0 || !isset($available[$locationCode])) {
                continue;
            }

            $locationType = isset($row['location_type']) ? sanitize_text_field((string) $row['location_type']) : 'secondary';
            if (!in_array($locationType, ['primary', 'secondary'], true)) {
                $locationType = 'secondary';
            }

            $locations[] = [
                'location_type' => $locationType,
                'location_code' => $locationCode,
                'location_name' => $available[$locationCode],
                'priority' => 0,
            ];
        }

        return $this->siteRegistrar->normalizeLocationsOption($locations);
    }

    /**
     * @return list<array{code:int,name:string,label:string}>
     */
    private function getAvailableLocationOptions(): array
    {
        return [
            ['code' => 2036, 'name' => 'Australia', 'label' => 'Australia (2036)'],
            ['code' => 2124, 'name' => 'Canada', 'label' => 'Canada (2124)'],
            ['code' => 2356, 'name' => 'India', 'label' => 'India (2356)'],
            ['code' => 2504, 'name' => 'Morocco', 'label' => 'Morocco (2504)'],
            ['code' => 2554, 'name' => 'New Zealand', 'label' => 'New Zealand (2554)'],
            ['code' => 2586, 'name' => 'Pakistan', 'label' => 'Pakistan (2586)'],
            ['code' => 2682, 'name' => 'Saudi Arabia', 'label' => 'Saudi Arabia (2682)'],
            ['code' => 2702, 'name' => 'Singapore', 'label' => 'Singapore (2702)'],
            ['code' => 2784, 'name' => 'United Arab Emirates', 'label' => 'United Arab Emirates (2784)'],
            ['code' => 2826, 'name' => 'United Kingdom', 'label' => 'United Kingdom (2826)'],
            ['code' => 2840, 'name' => 'United States', 'label' => 'United States (2840)'],
        ];
    }

    /** @param array<string,mixed> $payload @return list<string> */
    private function collectInsightItemsByType(array $payload, string $type, int $limit): array
    {
        $items = [];
        foreach ((array)($payload['insights'] ?? []) as $insight) {
            if (!is_array($insight) || (string)($insight['insight_type'] ?? '') !== $type) continue;
            $v = trim((string)($insight['details'] ?? $insight['headline'] ?? ''));
            if ($v === '') continue;
            $items[] = $v;
            if (count($items) >= $limit) break;
        }
        return $items;
    }

    private function renderAdminShellHeader(string $title, string $activePage, string $description = ''): void
    {
        $tabs = [
            'seoauto'              => ['label'=>'Settings',       'cap'=>'manage_options'],
            'seoauto-logs'         => ['label'=>'Change Center',  'cap'=>'manage_options'],
            'seoauto-action-items' => ['label'=>'Action Items',   'cap'=>'manage_options'],
            'seoauto-briefs'       => ['label'=>'Content Briefs', 'cap'=>'edit_posts'],
        ];
        ?>
        <div class="seoauto-shell-header">
            <h1><?php echo esc_html($title); ?></h1>
            <?php if ($description !== '') : ?><p><?php echo esc_html($description); ?></p><?php endif; ?>
            <div class="seoauto-shell-tabs">
                <?php foreach ($tabs as $slug => $tab) : ?>
                    <?php if (!current_user_can((string)$tab['cap'])) continue; ?>
                    <a class="seoauto-shell-tab <?php echo $slug === $activePage ? 'is-active' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page='.$slug)); ?>">
                        <?php echo esc_html((string)$tab['label']); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    private function resolveActionRedirectPage(): string
    {
        $returnPage = isset($_POST['return_page']) ? sanitize_text_field((string)$_POST['return_page']) : ''; // phpcs:ignore
        return in_array($returnPage, ['seoauto-logs','seoauto-action-items'], true) ? $returnPage : 'seoauto-logs';
    }

    public function sanitizeBaseUrl($value): string
    {
        return rtrim((string) SEOAUTO_LARAVEL_BASE_URL, '/');
    }

    public function sanitizeChangeApplicationMode($value): string
    {
        $mode = trim((string)$value);
        return in_array($mode, ['dangerous_auto_apply','review_before_apply'], true) ? $mode : 'dangerous_auto_apply';
    }

    public function sanitizeExcludedChangeAuditPages($value): string
    {
        $raw = trim((string)$value);
        if ($raw === '') return '';
        $tokens = preg_split('/[\r\n,]+/', $raw) ?: [];
        $clean  = [];
        foreach ($tokens as $token) {
            $n = trim((string)$token);
            if ($n !== '') $clean[] = sanitize_text_field($n);
        }
        return implode("\n", array_values(array_unique($clean)));
    }

    private function isBaseUrlSyntaxValid(string $url): bool
    {
        $scheme = wp_parse_url($url, PHP_URL_SCHEME);
        $host   = wp_parse_url($url, PHP_URL_HOST);
        if (!is_string($scheme) || !in_array(strtolower($scheme), ['http','https'], true)) return false;
        return is_string($host) && $host !== '';
    }
}
