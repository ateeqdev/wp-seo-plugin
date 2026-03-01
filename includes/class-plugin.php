<?php

declare(strict_types=1);

namespace SEOAutomation\Connector;

use SEOAutomation\Connector\Actions\ActionExecutor;
use SEOAutomation\Connector\Actions\ActionPoller;
use SEOAutomation\Connector\Actions\ActionReceiver;
use SEOAutomation\Connector\Actions\ActionRepository;
use SEOAutomation\Connector\Actions\RedirectRuntime;
use SEOAutomation\Connector\Actions\RobotsRuntime;
use SEOAutomation\Connector\Actions\StatusReporter;
use SEOAutomation\Connector\Admin\MenuRegistrar;
use SEOAutomation\Connector\API\ApiClient;
use SEOAutomation\Connector\API\LaravelClient;
use SEOAutomation\Connector\API\RetryPolicy;
use SEOAutomation\Connector\Auth\OAuthHandler;
use SEOAutomation\Connector\Auth\SiteTokenManager;
use SEOAutomation\Connector\Auth\TokenEncryption;
use SEOAutomation\Connector\Events\EventCollector;
use SEOAutomation\Connector\Events\EventDispatcher;
use SEOAutomation\Connector\Events\EventMapper;
use SEOAutomation\Connector\Events\EventOutbox;
use SEOAutomation\Connector\Queue\QueueManager;
use SEOAutomation\Connector\REST\ActionsEndpoint;
use SEOAutomation\Connector\REST\MediaEndpoint;
use SEOAutomation\Connector\REST\PagesEndpoint;
use SEOAutomation\Connector\SEO\SeoDetector;
use SEOAutomation\Connector\Storage\Schema;
use SEOAutomation\Connector\Sync\BriefSyncer;
use SEOAutomation\Connector\Sync\HealthChecker;
use SEOAutomation\Connector\Sync\SiteRegistrar;
use SEOAutomation\Connector\Sync\UserSyncer;
use SEOAutomation\Connector\Utils\LockManager;
use SEOAutomation\Connector\Utils\Logger;

final class Plugin
{
    private static ?self $instance = null;

    private ServiceContainer $container;

    private function __construct()
    {
        $this->container = new ServiceContainer();
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
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
        $this->registerServices();
        $this->registerHooks();
    }

    private function registerServices(): void
    {
        $this->container->register('logger', static fn (): Logger => new Logger(), true);
        $this->container->register('token_encryption', static fn (): TokenEncryption => new TokenEncryption(), true);
        $this->container->register(
            'site_token_manager',
            fn (ServiceContainer $c): SiteTokenManager => new SiteTokenManager($c->get('token_encryption')),
            true
        );
        $this->container->register('retry_policy', static fn (): RetryPolicy => new RetryPolicy(), false);
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
            fn (): ActionRepository => new ActionRepository(),
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
            fn (): EventOutbox => new EventOutbox(),
            true
        );
        $this->container->register(
            'event_mapper',
            fn (): EventMapper => new EventMapper(),
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
                $c->get('logger')
            ),
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
        $this->container->get('queue_manager')->registerHooks();
        $this->container->get('event_collector')->registerHooks();
        $this->container->get('menu_registrar')->registerHooks();

        add_action('rest_api_init', function (): void {
            $this->container->get('pages_endpoint')->registerRoutes();
            $this->container->get('media_endpoint')->registerRoutes();
            $this->container->get('actions_endpoint')->registerRoutes();
        });

        add_action('wp_head', function (): void {
            $adapter = SeoDetector::instance()->getAdapter();
            if (method_exists($adapter, 'renderMetaTags')) {
                $adapter->renderMetaTags();
            }
        }, 1);

        add_action('admin_init', [$this, 'checkCronHealth']);
        RedirectRuntime::registerHooks();
        RobotsRuntime::registerHooks();
    }

    public function checkCronHealth(): void
    {
        $lastRun = (int) get_option('seoauto_last_cron_run', 0);

        if ($lastRun > 0 && (time() - $lastRun) <= (5 * MINUTE_IN_SECONDS)) {
            return;
        }

        if (function_exists('spawn_cron')) {
            spawn_cron();
        }
    }
}
