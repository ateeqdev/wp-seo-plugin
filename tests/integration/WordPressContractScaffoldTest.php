<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SEOWorkerAI\Connector\API\LaravelClient;
use SEOWorkerAI\Connector\REST\ActionsEndpoint;
use SEOWorkerAI\Connector\REST\MediaEndpoint;
use SEOWorkerAI\Connector\REST\PagesEndpoint;
use SEOWorkerAI\Connector\REST\SiteProfileEndpoint;

final class WordPressContractScaffoldTest extends TestCase
{
    public function test_laravel_client_exposes_contract_methods(): void
    {
        $reflection = new ReflectionClass(LaravelClient::class);

        $required = [
            'registerSite',
            'updateSiteRegistration',
            'verifySite',
            'health',
            'initializeGoogleOAuth',
            'revokeGoogleOAuth',
            'sendEvent',
            'fetchPendingActions',
            'reportActionStatus',
            'rotateSiteToken',
            'updateSiteProfile',
            'listTasks',
            'updateTaskConfig',
            'scheduleTask',
            'listScheduledTasks',
            'listExecutionLogs',
            'listContentBriefs',
            'linkArticleToBrief',
            'dispatchAction',
        ];

        foreach ($required as $method) {
            self::assertTrue($reflection->hasMethod($method), "Missing LaravelClient contract method: {$method}");
        }
    }

    public function test_rest_endpoint_classes_exist_for_word_press_inbound_contract(): void
    {
        self::assertTrue(class_exists(SiteProfileEndpoint::class));
        self::assertTrue(class_exists(PagesEndpoint::class));
        self::assertTrue(class_exists(MediaEndpoint::class));
        self::assertTrue(class_exists(ActionsEndpoint::class));
    }

    public function test_word_press_runtime_contract_scaffold(): void
    {
        $this->markTestSkipped(
            'Scaffold: run under WordPress integration bootstrap to assert route registration and OAuth callback option persistence.'
        );
    }
}
