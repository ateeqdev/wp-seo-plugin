<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SEOAutomation\Connector\API\LaravelClient;
use SEOAutomation\Connector\REST\ActionsEndpoint;
use SEOAutomation\Connector\REST\MediaEndpoint;
use SEOAutomation\Connector\REST\PagesEndpoint;

final class WordPressContractScaffoldTest extends TestCase
{
    public function testLaravelClientExposesContractMethods(): void
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

    public function testRestEndpointClassesExistForWordPressInboundContract(): void
    {
        self::assertTrue(class_exists(PagesEndpoint::class));
        self::assertTrue(class_exists(MediaEndpoint::class));
        self::assertTrue(class_exists(ActionsEndpoint::class));
    }

    public function testWordPressRuntimeContractScaffold(): void
    {
        $this->markTestSkipped(
            'Scaffold: run under WordPress integration bootstrap to assert route registration and OAuth callback option persistence.'
        );
    }
}
