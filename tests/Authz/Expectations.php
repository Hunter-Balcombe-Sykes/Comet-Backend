<?php

namespace Tests\Authz;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads tests/Authz/expectations.yaml — the only hand-maintained data in this
 * lane.
 *
 * Validation is strict and fails the run rather than warning: an unknown key is
 * a typo that would otherwise silently do nothing, and an exemption without a
 * reason is how a suppression file becomes unreviewable six months later. The
 * DAST tool's own rule — baselines: triage, don't pre-seed — applies here with
 * more force, because this file is the ONLY way a route leaves the matrix.
 */
final class Expectations
{
    private const KNOWN_KEYS = ['route', 'expect', 'reason', 'fixture', 'body'];

    /** @param  array<string, array<string, mixed>>  $entries  pattern => entry */
    private function __construct(private readonly array $entries) {}

    public static function load(): self
    {
        $path = __DIR__.'/expectations.yaml';

        if (! is_file($path)) {
            throw new RuntimeException("Missing expectations file: {$path}");
        }

        return self::fromString((string) file_get_contents($path));
    }

    public static function fromString(string $yaml): self
    {
        $parsed = Yaml::parse($yaml) ?? [];

        if (! is_array($parsed)) {
            throw new RuntimeException('expectations.yaml must be a list of entries.');
        }

        $entries = [];

        foreach ($parsed as $i => $entry) {
            if (! is_array($entry) || ! isset($entry['route'])) {
                throw new RuntimeException("expectations.yaml entry #{$i} has no `route:` key.");
            }

            $route = (string) $entry['route'];

            foreach (array_keys($entry) as $key) {
                if (! in_array($key, self::KNOWN_KEYS, true)) {
                    throw new RuntimeException(
                        "expectations.yaml entry `{$route}` has unknown key `{$key}`. "
                        .'Known keys: '.implode(', ', self::KNOWN_KEYS).'.'
                    );
                }
            }

            if (is_int($entry['expect'] ?? null) && trim((string) ($entry['reason'] ?? '')) === '') {
                throw new RuntimeException(
                    "expectations.yaml entry `{$route}` declares expect: {$entry['expect']} but has no "
                    .'`reason:`. A non-404 expectation is a claim that this rejection shape is correct — say why.'
                );
            }

            if (($entry['expect'] ?? null) === 'exempt') {
                $reason = trim((string) ($entry['reason'] ?? ''));

                if ($reason === '') {
                    throw new RuntimeException(
                        "expectations.yaml entry `{$route}` is exempt but has no `reason:`. "
                        .'Say what the param actually identifies and why it is not tenant-scoped.'
                    );
                }
            }

            if (isset($entries[$route])) {
                throw new RuntimeException("expectations.yaml has duplicate entries for `{$route}`.");
            }

            $entries[$route] = $entry;
        }

        return new self($entries);
    }

    /**
     * Look up an entry, preferring a METHOD-SCOPED key.
     *
     * `route:` may be either a bare pattern ("api/foo/{id}") or a
     * method-scoped one ("PUT api/foo/{id}"). Method scoping matters because
     * verbs on one path genuinely differ: GET api/content/items/{item}/overrides
     * correctly answers 404, while PUT on the identical path answers 422
     * because validation runs first. A pattern-only `expect:` would force one
     * of the two to be declared wrong.
     */
    private function lookup(string $pattern, ?string $method): ?array
    {
        if ($method !== null && isset($this->entries[$method.' '.$pattern])) {
            return $this->entries[$method.' '.$pattern];
        }

        return $this->entries[$pattern] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function entryFor(string $pattern, ?string $method = null): ?array
    {
        return $this->lookup($pattern, $method);
    }

    /**
     * Per-route expected status, defaulting to 404.
     *
     * Declaring a non-404 expectation is a claim that the route's rejection is
     * correct but differently shaped — e.g. a capability gate that legitimately
     * answers 403 before existence is ever consulted. It requires a reason, and
     * the reason is what a reviewer checks.
     */
    public function expectedStatus(string $pattern, ?string $method = null): int
    {
        $expect = $this->lookup($pattern, $method)['expect'] ?? null;

        return is_int($expect) ? $expect : 404;
    }

    public function isExempt(string $pattern, ?string $method = null): bool
    {
        return ($this->lookup($pattern, $method)['expect'] ?? null) === 'exempt';
    }

    public function fixtureFor(string $pattern, string $param, ?string $method = null): ?string
    {
        $fixture = $this->lookup($pattern, $method)['fixture'] ?? [];

        return isset($fixture[$param]) ? (string) $fixture[$param] : null;
    }

    /** @return array<string, mixed> */
    public function bodyFor(string $pattern, ?string $method = null): array
    {
        return (array) ($this->lookup($pattern, $method)['body'] ?? []);
    }

    /**
     * Every pattern named in the file, with any method prefix stripped, so the
     * stale-entry guard compares like with like.
     *
     * @return array<int, string>
     */
    public function patterns(): array
    {
        return array_values(array_unique(array_map(
            static function (string $key): string {
                $parts = explode(' ', $key, 2);

                return count($parts) === 2 && ctype_upper($parts[0]) ? $parts[1] : $key;
            },
            array_keys($this->entries),
        )));
    }
}
