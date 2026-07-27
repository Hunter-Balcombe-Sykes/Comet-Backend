<?php

namespace App\Ingest\Manifest;

/**
 * A connector's only declarative artefact (plan §4: the Manifest owns stream
 * declarations, NOT the catalog surface — the surface names a capability, and
 * that capability's connector ships this).
 *
 * `hosts` is enforced by EffectAdmission: a connector cannot reach a host it
 * did not declare, so a derived URL from vendor data can never become an
 * unbounded fetch of somewhere else.
 */
final readonly class Manifest
{
    /**
     * @param  list<string>  $hosts  host globs this connector may contact
     * @param  array<string, StreamSpec>  $streams
     * @param  list<string>  $redactions  JSON paths stripped BEFORE landing
     * @param  array<string, string>  $redactionScopes  path => 'always' | 'when_unclaimed'
     */
    public function __construct(
        public SourceKey $source,
        public string $identifierKind,
        public array $hosts,
        public array $streams,
        public CostClass $cost = CostClass::Free,
        public int $defaultIntervalSeconds = 86400,
        public array $redactions = [],
        public array $redactionScopes = [],
    ) {}

    public function stream(string $name): ?StreamSpec
    {
        return $this->streams[$name] ?? null;
    }

    /** @return list<string> */
    public function streamNames(): array
    {
        return array_keys($this->streams);
    }

    /**
     * Redactions that apply for this claim state. Claimed accounts keep the
     * fuller record: the strip exists because we hold data for people who
     * have not asked us to, which stops being true once they claim.
     *
     * @return list<string>
     */
    public function redactionsFor(bool $isClaimed): array
    {
        $out = [];
        foreach ($this->redactions as $path) {
            $scope = $this->redactionScopes[$path] ?? 'always';
            if ($scope === 'always' || ! $isClaimed) {
                $out[] = $path;
            }
        }

        return $out;
    }

    public function mayContact(string $host): bool
    {
        $host = strtolower($host);
        foreach ($this->hosts as $glob) {
            if (fnmatch(strtolower($glob), $host)) {
                return true;
            }
        }

        return false;
    }
}
