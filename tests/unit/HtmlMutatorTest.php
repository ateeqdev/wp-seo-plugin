<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SEOAutomation\Connector\Content\HtmlMutator;

final class HtmlMutatorTest extends TestCase
{
    public function testReplaceUrlReferencesUpdatesHrefAndText(): void
    {
        $html = '<p>Visit <a href="https://example.com/old">our old page</a> or https://example.com/old today.</p>';
        $count = 0;

        $result = HtmlMutator::replaceUrlReferences(
            $html,
            'https://example.com/old',
            'https://example.com/new',
            $count
        );

        self::assertStringContainsString('https://example.com/new', $result);
        self::assertSame(2, $count);
    }

    public function testInsertInternalLinkUsesAnchorMatchWhenPresent(): void
    {
        $html = '<p>This article explains technical SEO and metadata.</p>';
        $matched = false;

        $result = HtmlMutator::insertInternalLink(
            $html,
            'technical SEO',
            'https://example.com/technical-seo',
            $matched
        );

        self::assertTrue($matched);
        self::assertStringContainsString('<a href="https://example.com/technical-seo">technical SEO</a>', $result);
    }

    public function testInsertInternalLinkAppendsWhenAnchorMissing(): void
    {
        $html = '<p>Simple paragraph.</p>';
        $matched = false;

        $result = HtmlMutator::insertInternalLink(
            $html,
            'read more',
            'https://example.com/read-more',
            $matched
        );

        self::assertFalse($matched);
        self::assertStringContainsString('https://example.com/read-more', $result);
    }
}
