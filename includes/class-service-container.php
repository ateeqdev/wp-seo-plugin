<?php

declare(strict_types=1);

namespace SEOAutomation\Connector;

use InvalidArgumentException;

final class ServiceContainer
{
    /**
     * @var array<string, callable>
     */
    private array $factories = [];

    /**
     * @var array<string, bool>
     */
    private array $shared = [];

    /**
     * @var array<string, mixed>
     */
    private array $instances = [];

    /**
     * @param callable(self):mixed $factory
     */
    public function register(string $id, callable $factory, bool $shared = true): void
    {
        $this->factories[$id] = $factory;
        $this->shared[$id] = $shared;

        if (!$shared && isset($this->instances[$id])) {
            unset($this->instances[$id]);
        }
    }

    /**
     * @return mixed
     */
    public function get(string $id)
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (!isset($this->factories[$id])) {
            throw new InvalidArgumentException(sprintf('Service "%s" not registered.', $id));
        }

        $instance = ($this->factories[$id])($this);

        if (($this->shared[$id] ?? false) === true) {
            $this->instances[$id] = $instance;
        }

        return $instance;
    }
}
