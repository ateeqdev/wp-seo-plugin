<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\Content;

final class HtmlMutator
{
    /**
     * @param int $replacements Out parameter.
     */
    public static function replaceUrlReferences(string $html, string $brokenUrl, string $replacementUrl, int &$replacements): string
    {
        $replacements = 0;

        if ($html === '' || $brokenUrl === '' || $replacementUrl === '') {
            return $html;
        }

        if (!class_exists('DOMDocument')) {
            $count = 0;
            $mutated = str_replace($brokenUrl, $replacementUrl, $html, $count);
            $replacements = $count;
            return $mutated;
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML(
            '<?xml encoding="utf-8" ?><div id="seoworkerai-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        if (!$loaded) {
            $count = 0;
            $mutated = str_replace($brokenUrl, $replacementUrl, $html, $count);
            $replacements = $count;
            return $mutated;
        }

        $xpath = new \DOMXPath($doc);

        foreach ($xpath->query('//*[@href]') as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $href = trim((string) $node->getAttribute('href'));
            if ($href === $brokenUrl) {
                $node->setAttribute('href', $replacementUrl);
                $replacements++;
            }
        }

        foreach ($xpath->query('//text()') as $textNode) {
            if (!$textNode instanceof \DOMText) {
                continue;
            }

            $parent = $textNode->parentNode;
            if ($parent instanceof \DOMElement && strtolower($parent->nodeName) === 'a') {
                continue;
            }

            $before = $textNode->wholeText;
            $count = 0;
            $after = str_replace($brokenUrl, $replacementUrl, $before, $count);
            if ($count > 0) {
                $textNode->replaceData(0, strlen($before), $after);
                $replacements += $count;
            }
        }

        return self::innerHtmlById($doc, 'seoworkerai-root');
    }

    /**
     * Inserts an internal link either by wrapping first text match or appending a sentence.
     */
    public static function insertInternalLink(string $html, string $anchorText, string $toUrl, bool &$insertedByMatch): string
    {
        $insertedByMatch = false;

        if ($html === '' || $anchorText === '' || $toUrl === '') {
            return $html;
        }

        if (stripos($html, 'href="' . $toUrl . '"') !== false || stripos($html, "href='" . $toUrl . "'") !== false) {
            return $html;
        }

        if (!class_exists('DOMDocument')) {
            $pattern = '/' . preg_quote($anchorText, '/') . '/i';
            if (preg_match($pattern, $html) === 1) {
                $insertedByMatch = true;
                return preg_replace(
                    $pattern,
                    sprintf('<a href="%s">%s</a>', self::escapeAttr($toUrl), self::escapeHtml($anchorText)),
                    $html,
                    1
                ) ?? $html;
            }

            return rtrim($html) . ' <a href="' . self::escapeAttr($toUrl) . '">' . self::escapeHtml($anchorText) . '</a>';
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML(
            '<?xml encoding="utf-8" ?><div id="seoworkerai-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        if (!$loaded) {
            return rtrim($html) . ' <a href="' . self::escapeAttr($toUrl) . '">' . self::escapeHtml($anchorText) . '</a>';
        }

        $xpath = new \DOMXPath($doc);

        foreach ($xpath->query('//text()') as $textNode) {
            if (!$textNode instanceof \DOMText) {
                continue;
            }

            $parent = $textNode->parentNode;
            if (!$parent instanceof \DOMNode) {
                continue;
            }
            if ($parent instanceof \DOMElement && strtolower($parent->nodeName) === 'a') {
                continue;
            }

            $text = $textNode->wholeText;
            $offset = stripos($text, $anchorText);
            if ($offset === false) {
                continue;
            }

            $matched = substr($text, $offset, strlen($anchorText));
            $before = substr($text, 0, $offset);
            $after = substr($text, $offset + strlen($anchorText));

            if ($before !== '') {
                $parent->insertBefore(new \DOMText($before), $textNode);
            }

            $anchor = $doc->createElement('a', $matched);
            $anchor->setAttribute('href', $toUrl);
            $parent->insertBefore($anchor, $textNode);

            if ($after !== '') {
                $parent->insertBefore(new \DOMText($after), $textNode);
            }

            $parent->removeChild($textNode);
            $insertedByMatch = true;

            return self::innerHtmlById($doc, 'seoworkerai-root');
        }

        $root = $doc->getElementById('seoworkerai-root');
        if ($root instanceof \DOMElement) {
            $space = $doc->createTextNode(' ');
            $root->appendChild($space);
            $anchor = $doc->createElement('a', $anchorText);
            $anchor->setAttribute('href', $toUrl);
            $root->appendChild($anchor);
        }

        return self::innerHtmlById($doc, 'seoworkerai-root');
    }

    private static function innerHtmlById(\DOMDocument $doc, string $id): string
    {
        $root = $doc->getElementById($id);

        if (!$root instanceof \DOMElement) {
            return '';
        }

        $html = '';
        foreach ($root->childNodes as $child) {
            $html .= $doc->saveHTML($child) ?: '';
        }

        return $html;
    }

    private static function escapeAttr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
