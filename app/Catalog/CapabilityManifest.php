<?php

namespace App\Catalog;

/**
 * Capability plane: versioned capability NAME → [class, config]. Surfaces
 * reference capabilities by name only; resolution happens at call time via
 * app()->makeWith() — never class names in data, never boot-time
 * instantiation. Names are versioned (`fetch.instagram.apify.v1`) so two
 * implementations can coexist during a migration; retiring one is deleting
 * its manifest line once no surface names it.
 *
 * P1 note: entries map to the EXISTING service classes so behaviour is
 * unchanged; P3+ swaps entries to the new connector runtime without touching
 * any surface definition.
 */
class CapabilityManifest
{
    /**
     * Entries are either [class, namedConstructorArgs] — resolved via
     * app()->makeWith($class, $args), so keys must match the constructor's
     * parameter names — or a factory \Closure for compositions the container
     * can't express declaratively (e.g. UrlConnect wrapping a specific
     * normalizer instance). Closures are legal here because this file is the
     * CODE plane: only capability NAMES enter the compiled artefact.
     *
     * @return array<string, array{0: class-string, 1: array<string, mixed>}|\Closure>
     */
    public static function all(): array
    {
        return require __DIR__.'/Definitions/_capabilities.php';
    }

    public static function has(string $name): bool
    {
        return array_key_exists($name, self::all());
    }

    /** @return array{0: class-string, 1: array<string, mixed>}|\Closure|null */
    public static function entry(string $name): array|\Closure|null
    {
        return self::all()[$name] ?? null;
    }

    /** Resolve a capability to a live instance — call-time, config injected. */
    public static function resolve(string $name): object
    {
        $entry = self::entry($name);
        if ($entry === null) {
            throw new \InvalidArgumentException("Unknown capability: {$name}");
        }
        if ($entry instanceof \Closure) {
            return $entry();
        }
        [$class, $args] = $entry;

        return $args === []
            ? app($class)
            : app()->makeWith($class, $args);
    }
}
