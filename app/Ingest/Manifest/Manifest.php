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
        public bool $eagerOnConnect = false,
    ) {}

    /**
     * Whether provisioning a NEW source for this connector should run it once
     * immediately, rather than leaving it for the scheduler.
     *
     * Free connectors always do (F21, 2026-08-18): the dispatcher tick is 15
     * minutes, so "connect Apple Music → the listen pool stays empty for a
     * quarter of an hour" was the default experience. The eager run costs
     * nothing, and it is claimed before dispatch and released with a fresh
     * next_attempt_at, so the scheduler does not fetch the same source again
     * on its next tick — one fetch either way, just now instead of later.
     *
     * Paid connectors stay opt-in. SourceProvisioner sets auto_sync =
     * (cost === Free) and SourceScheduler::scoreDue() selects only auto_sync =
     * true, so a paid connector is never claimed by the scheduler and — absent
     * the flag — never runs at all. Seven connectors are paid (six Actor, one
     * Metered); flipping them on together would start third-party spend on
     * every connect. Opting in is one visible line per connector.
     */
    public function runsEagerlyOnConnect(): bool
    {
        return $this->eagerOnConnect || $this->cost === CostClass::Free;
    }

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
