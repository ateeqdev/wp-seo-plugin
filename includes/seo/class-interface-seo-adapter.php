<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\SEO;

interface InterfaceSeoAdapter
{
    public function getTitle(int $postId): ?string;

    public function setTitle(int $postId, string $title): bool;

    public function getDescription(int $postId): ?string;

    public function setDescription(int $postId, string $description): bool;

    public function getCanonical(int $postId): ?string;

    public function setCanonical(int $postId, string $url): bool;

    /**
     * @return array<string, bool>
     */
    public function getRobots(int $postId): array;

    /**
     * @param array<string, bool> $robots
     */
    public function setRobots(int $postId, array $robots): bool;

    /**
     * @return array<string, mixed>|null
     */
    public function getSchema(int $postId): ?array;

    /**
     * @param array<string, mixed> $schema
     */
    public function setSchema(int $postId, array $schema): bool;

    public function getName(): string;
}
