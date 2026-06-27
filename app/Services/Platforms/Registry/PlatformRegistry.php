<?php

namespace App\Services\Platforms\Registry;

// The single source of truth for which platforms exist and what each is. Bound as
// a singleton in PlatformRegistryServiceProvider. Consumers (validation now; the
// refresher, detector, and generic controller in later plans) read this instead
// of hard-coded platform lists.
class PlatformRegistry
{
    /** @var array<string, PlatformDescriptor> */
    private array $descriptors = [];

    public function register(PlatformDescriptor $descriptor): self
    {
        $this->descriptors[$descriptor->key()] = $descriptor;

        return $this;
    }

    public function get(string $key): ?PlatformDescriptor
    {
        return $this->descriptors[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->descriptors[$key]);
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys($this->descriptors);
    }

    /** @return array<string, PlatformDescriptor> */
    public function all(): array
    {
        return $this->descriptors;
    }

    /** @return array<string, PlatformDescriptor> */
    public function refreshable(): array
    {
        return array_filter($this->descriptors, fn (PlatformDescriptor $d) => $d->isRefreshable());
    }
}
