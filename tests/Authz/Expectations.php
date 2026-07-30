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

    /** @return array<string, mixed>|null */
    public function entryFor(string $pattern): ?array
    {
        return $this->entries[$pattern] ?? null;
    }

    public function isExempt(string $pattern): bool
    {
        return ($this->entries[$pattern]['expect'] ?? null) === 'exempt';
    }

    public function fixtureFor(string $pattern, string $param): ?string
    {
        $fixture = $this->entries[$pattern]['fixture'] ?? [];

        return isset($fixture[$param]) ? (string) $fixture[$param] : null;
    }

    /** @return array<string, mixed> */
    public function bodyFor(string $pattern): array
    {
        return (array) ($this->entries[$pattern]['body'] ?? []);
    }

    /** @return array<int, string> every pattern named in the file */
    public function patterns(): array
    {
        return array_keys($this->entries);
    }
}
