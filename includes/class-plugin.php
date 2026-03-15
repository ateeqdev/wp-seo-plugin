<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector;

use SEOWorkerAI\Connector\Actions\ActionExecutor;
use SEOWorkerAI\Connector\Actions\ActionPoller;
use SEOWorkerAI\Connector\Actions\ActionReceiver;
use SEOWorkerAI\Connector\Actions\ActionRepository;
use SEOWorkerAI\Connector\Actions\RedirectRuntime;
use SEOWorkerAI\Connector\Actions\RobotsRuntime;
use SEOWorkerAI\Connector\Actions\StatusReporter;
use SEOWorkerAI\Connector\Admin\MenuRegistrar;
use SEOWorkerAI\Connector\API\ApiClient;
use SEOWorkerAI\Connector\API\LaravelClient;
use SEOWorkerAI\Connector\API\RetryPolicy;
use SEOWorkerAI\Connector\Auth\OAuthHandler;
use SEOWorkerAI\Connector\Auth\SiteTokenManager;
use SEOWorkerAI\Connector\Auth\TokenEncryption;
use SEOWorkerAI\Connector\Events\EventCollector;
use SEOWorkerAI\Connector\Events\EventDispatcher;
use SEOWorkerAI\Connector\Events\EventMapper;
use SEOWorkerAI\Connector\Events\EventOutbox;
use SEOWorkerAI\Connector\Queue\QueueManager;
use SEOWorkerAI\Connector\REST\ActionsEndpoint;
use SEOWorkerAI\Connector\REST\MediaEndpoint;
use SEOWorkerAI\Connector\REST\OwnershipProofEndpoint;
use SEOWorkerAI\Connector\REST\PagesEndpoint;
use SEOWorkerAI\Connector\REST\RestAccessCompatibility;
use SEOWorkerAI\Connector\REST\SiteProfileEndpoint;
use SEOWorkerAI\Connector\SEO\SeoDetector;
use SEOWorkerAI\Connector\Storage\Schema;
use SEOWorkerAI\Connector\Sync\BriefSyncer;
use SEOWorkerAI\Connector\Sync\HealthChecker;
use SEOWorkerAI\Connector\Sync\SiteRegistrar;
use SEOWorkerAI\Connector\Sync\UserSyncer;
use SEOWorkerAI\Connector\Utils\LockManager;
use SEOWorkerAI\Connector\Utils\Logger;

final class Plugin
{
    private static ?self $instance = null;

    private ServiceContainer $container;

    private function __construct()
    {
        $this->container = new ServiceContainer;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    public function boot(): void
    {
        add_action('plugins_loaded', [$this, 'onPluginsLoaded']);
    }

    public function onPluginsLoaded(): void
    {
        Schema::createOrUpgrade();
        update_option('seoworkerai_base_url', rtrim((string) SEOWORKERAI_LARAVEL_BASE_URL, '/'), false);
        $this->registerServices();
        $this->registerHooks();
    }

    private function registerServices(): void
    {
        $this->container->register('logger', static fn (): Logger => new Logger, true);
        $this->container->register('token_encryption', static fn (): TokenEncryption => new TokenEncryption, true);
        $this->container->register(
            'site_token_manager',
            fn (ServiceContainer $c): SiteTokenManager => new SiteTokenManager($c->get('token_encryption')),
            true
        );
        $this->container->register('retry_policy', static fn (): RetryPolicy => new RetryPolicy, false);
        $this->container->register(
            'api_client',
            fn (ServiceContainer $c): ApiClient => new ApiClient($c->get('site_token_manager'), $c->get('logger')),
            true
        );
        $this->container->register(
            'laravel_client',
            fn (ServiceContainer $c): LaravelClient => new LaravelClient($c->get('api_client'), $c->get('retry_policy'), $c->get('logger')),
            true
        );
        $this->container->register(
            'lock_manager',
            fn (ServiceContainer $c): LockManager => new LockManager($c->get('logger')),
            true
        );
        $this->container->register(
            'action_repository',
            fn (): ActionRepository => new ActionRepository,
            true
        );
        $this->container->register(
            'status_reporter',
            fn (ServiceContainer $c): StatusReporter => new StatusReporter(
                $c->get('laravel_client'),
                $c->get('action_repository'),
                $c->get('logger')
            ),
            true
        );
        $this->container->register(
            'action_executor',
            fn (ServiceContainer $c): ActionExecutor => new ActionExecutor(
                $c->get('action_repository'),
                $c->get('status_reporter'),
                $c->get('lock_manager'),
                $c->get('logger')
            ),
            true
        );
        $this->container->register(
            'action_receiver',
            fn (ServiceContainer $c): ActionReceiver => new ActionReceiver(
                $c->get('action_repository'),
                $c->get('logger')
            ),
            true
        );
        $this->container->register(
            'action_poller',
            fn (ServiceContainer $c): ActionPoller => new ActionPoller(
                $c->get('laravel_client'),
                $c->get('action_receiver'),
                $c->get('logger')
            ),
            true
        );
        $this->container->register(
            'event_outbox',
            fn (): EventOutbox => new EventOutbox,
            true
        );
        $this->container->register(
            'event_mapper',
            fn (): EventMapper => new EventMapper,
            true
        );
        $this->container->register(
            'event_dispatcher',
            fn (ServiceContainer $c): EventDispatcher => new EventDispatcher(
                $c->get('laravel_client'),
                $c->get('event_outbox'),
                $c->get('logger')
            ),
            true
        );
        $this->container->register(
            'event_collector',
            fn (ServiceContainer $c): EventCollector => new EventCollector(
                $c->get('event_mapper'),
                $c->get('event_outbox'),
                $c->get('logger')
            ),
            true
        );
        $this->container->register(
            'site_registrar',
            fn (ServiceContainer $c): SiteRegistrar => new SiteRegistrar($c->get('laravel_client'), $c->get('site_token_manager'), $c->get('logger')),
            true
        );
        $this->container->register(
            'health_checker',
            fn (ServiceContainer $c): HealthChecker => new HealthChecker($c->get('laravel_client'), $c->get('logger')),
            true
        );
        $this->container->register(
            'oauth_handler',
            fn (ServiceContainer $c): OAuthHandler => new OAuthHandler(
                $c->get('laravel_client'),
                $c->get('health_checker'),
                $c->get('logger')
            ),
            true
        );
        $this->container->register(
            'user_syncer',
            fn (ServiceContainer $c): UserSyncer => new UserSyncer($c->get('laravel_client'), $c->get('logger')),
            true
        );
        $this->container->register(
            'brief_syncer',
            fn (ServiceContainer $c): BriefSyncer => new BriefSyncer($c->get('laravel_client'), $c->get('logger')),
            true
        );
        $this->container->register('seo_detector', static fn (): SeoDetector => SeoDetector::instance(), true);
        $this->container->register(
            'menu_registrar',
            fn (ServiceContainer $c): MenuRegistrar => new MenuRegistrar(
                $c->get('laravel_client'),
                $c->get('brief_syncer'),
                $c->get('site_registrar'),
                $c->get('health_checker'),
                $c->get('oauth_handler'),
                $c->get('site_token_manager'),
                $c->get('action_repository'),
                $c->get('action_executor'),
                $c->get('logger')
            ),
            true
        );
        $this->container->register(
            'site_profile_endpoint',
            fn (ServiceContainer $c): SiteProfileEndpoint => new SiteProfileEndpoint($c->get('site_token_manager')),
            true
        );
        $this->container->register(
            'pages_endpoint',
            fn (ServiceContainer $c): PagesEndpoint => new PagesEndpoint($c->get('site_token_manager'), $c->get('seo_detector')),
            true
        );
        $this->container->register(
            'media_endpoint',
            fn (ServiceContainer $c): MediaEndpoint => new MediaEndpoint($c->get('site_token_manager')),
            true
        );
        $this->container->register(
            'actions_endpoint',
            fn (ServiceContainer $c): ActionsEndpoint => new ActionsEndpoint($c->get('site_token_manager'), $c->get('action_receiver')),
            true
        );
        $this->container->register(
            'ownership_proof_endpoint',
            fn (): OwnershipProofEndpoint => new OwnershipProofEndpoint,
            true
        );
        $this->container->register(
            'rest_access_compatibility',
            fn (ServiceContainer $c): RestAccessCompatibility => new RestAccessCompatibility(
                $c->get('site_token_manager'),
                $c->get('logger')
            ),
            true
        );
        $this->container->register(
            'queue_manager',
            fn (ServiceContainer $c): QueueManager => new QueueManager(
                $c->get('event_dispatcher'),
                $c->get('action_poller'),
                $c->get('brief_syncer'),
                $c->get('user_syncer'),
                $c->get('action_executor'),
                $c->get('status_reporter'),
                $c->get('logger')
            ),
            true
        );
    }

    private function registerHooks(): void
    {
        $this->container->get('rest_access_compatibility')->registerHooks();
        $this->container->get('queue_manager')->registerHooks();
        $this->container->get('event_collector')->registerHooks();
        $this->container->get('menu_registrar')->registerHooks();
        add_action('seoworkerai_auto_register_site', [$this, 'handleAutoRegisterSite']);
        add_action('admin_init', [$this, 'maybeRedirectAfterActivation'], 1);
        add_action('admin_init', [$this, 'maybeRunImmediateAutoRegister'], 1);
        add_action('admin_init', [$this, 'maybeScheduleAutoRegister']);
        add_action('admin_init', [$this, 'checkCronHealth']);
        add_filter('plugin_action_links_'.plugin_basename(SEOWORKERAI_PLUGIN_FILE), [$this, 'filterPluginActionLinks']);

        add_action('rest_api_init', function (): void {
            $this->container->get('site_profile_endpoint')->registerRoutes();
            $this->container->get('pages_endpoint')->registerRoutes();
            $this->container->get('media_endpoint')->registerRoutes();
            $this->container->get('actions_endpoint')->registerRoutes();
            $this->container->get('ownership_proof_endpoint')->registerRoutes();
        });

        $urlStore = new \SEOWorkerAI\Connector\Storage\UrlMetaStore;
        $urlStore->registerFrontendHooks();

        $adapter = SeoDetector::instance()->getAdapter();
        if (method_exists($adapter, 'registerFrontendHooks')) {
            $adapter->registerFrontendHooks();
        } elseif (method_exists($adapter, 'renderMetaTags')) {
            add_action('wp_head', [$adapter, 'renderMetaTags'], 1);
        }

        RedirectRuntime::registerHooks();
        RobotsRuntime::registerHooks();
    }

    public function maybeScheduleAutoRegister(): void
    {
        $siteId = (int) get_option('seoworkerai_site_id', 0);
        $pending = (bool) get_option('seoworkerai_auto_register_pending', false);

        if (! $pending && $siteId > 0) {
            return;
        }

        if (! wp_next_scheduled('seoworkerai_auto_register_site')) {
            wp_schedule_single_event(time() + 20, 'seoworkerai_auto_register_site');
        }
    }

    public function maybeRunImmediateAutoRegister(): void
    {
        if ((function_exists('wp_doing_ajax') && wp_doing_ajax()) || (function_exists('wp_doing_cron') && wp_doing_cron())) {
            return;
        }

        $siteId = (int) get_option('seoworkerai_site_id', 0);
        $pending = (bool) get_option('seoworkerai_auto_register_pending', false);
        if ($siteId > 0 && ! $pending) {
            return;
        }

        $lockKey = 'seoworkerai_immediate_auto_register_lock';
        if (get_transient($lockKey)) {
            return;
        }

        set_transient($lockKey, 1, 30);
        $this->handleAutoRegisterSite();
    }

    public function maybeRedirectAfterActivation(): void
    {
        if (! is_admin() || ! current_user_can('manage_options')) {
            return;
        }
        if ((function_exists('wp_doing_ajax') && wp_doing_ajax()) || (function_exists('wp_doing_cron') && wp_doing_cron())) {
            return;
        }
        if (! ((bool) get_option('seoworkerai_activation_redirect', false))) {
            return;
        }
        update_option('seoworkerai_activation_redirect', false, false);
        wp_safe_redirect(add_query_arg(['page' => 'seoworkerai-settings', 'seoworkerai_notice' => 'domain_rating_required'], admin_url('admin.php')));
        exit;
    }

    public function handleAutoRegisterSite(): void
    {
        $result = $this->container->get('site_registrar')->registerOrUpdate(true);
        $siteId = (int) get_option('seoworkerai_site_id', 0);
        if (! isset($result['error']) && $siteId > 0) {
            update_option('seoworkerai_auto_register_pending', false, false);

            return;
        }

        update_option('seoworkerai_auto_register_pending', true, false);
        if (! wp_next_scheduled('seoworkerai_auto_register_site')) {
            wp_schedule_single_event(time() + 5 * MINUTE_IN_SECONDS, 'seoworkerai_auto_register_site');
        }
    }

    public function checkCronHealth(): void
    {
        $lastRun = (int) get_option('seoworkerai_last_cron_run', 0);

        if ($lastRun > 0 && (time() - $lastRun) <= (5 * MINUTE_IN_SECONDS)) {
            return;
        }

        if (function_exists('spawn_cron')) {
            spawn_cron();
        }
    }

    /**
     * @param  array<int, string>  $links
     * @return array<int, string>
     */
    public function filterPluginActionLinks(array $links): array
    {
        $settingsLink = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=seoworkerai')),
            esc_html__('Settings', 'seoworkerai')
        );
        $rateLink = sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
            esc_url('https://wordpress.org/support/plugin/seoworkerai/reviews/#new-post'),
            esc_html__('Rate this plugin', 'seoworkerai')
        );

        return array_merge([$settingsLink, $rateLink], $links);
    }
}
