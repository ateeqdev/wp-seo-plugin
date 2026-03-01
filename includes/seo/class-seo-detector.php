<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\SEO;

final class SeoDetector
{
    private static ?self $instance = null;

    /**
     * @var string[]
     */
    private array $detected = [];

    private function __construct()
    {
        $this->detect();
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function getAdapter(): InterfaceSeoAdapter
    {
        $forced = (string) get_option('seoauto_primary_seo_adapter', 'auto');
        if ($forced !== 'auto') {
            return $this->createAdapter($forced);
        }

        if (count($this->detected) === 0) {
            return new CoreAdapter();
        }

        if (count($this->detected) === 1) {
            return $this->createAdapter($this->detected[0]);
        }

        $priority = (array) get_option('seoauto_adapter_priority', ['yoast', 'rankmath', 'aioseo']);
        foreach ($priority as $candidate) {
            if (in_array($candidate, $this->detected, true)) {
                return $this->createAdapter($candidate);
            }
        }

        return new CoreAdapter();
    }

    private function detect(): void
    {
        if (defined('WPSEO_VERSION') || class_exists('WPSEO_Meta')) {
            $this->detected[] = 'yoast';
        }

        if (defined('RANK_MATH_VERSION') || class_exists('RankMath\\Helper')) {
            $this->detected[] = 'rankmath';
        }

        if (defined('AIOSEO_VERSION') || function_exists('aioseo')) {
            $this->detected[] = 'aioseo';
        }
    }

    private function createAdapter(string $name): InterfaceSeoAdapter
    {
        if ($name === 'yoast') {
            return new YoastAdapter();
        }

        if ($name === 'rankmath') {
            return new RankmathAdapter();
        }

        if ($name === 'aioseo') {
            return new AioseoAdapter();
        }

        return new CoreAdapter();
    }
}
