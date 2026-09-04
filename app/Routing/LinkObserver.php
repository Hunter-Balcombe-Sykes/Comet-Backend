<?php

namespace App\Routing;

use App\Catalog\CompiledCatalog;
use App\Catalog\UnmatchedDomains;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Append-only record of every link the router was asked about, and what it
 * decided. Never read on a request path — this exists so `routing:reproject`
 * can replay real traffic against a new rulepack, and so "why did it do
 * that?" always has an answer.
 *
 * Writes are best-effort by design: an observation failing must never fail
 * the user's action. The router's correctness does not depend on this table.
 */
class LinkObserver
{
    public function __construct(private readonly UnmatchedDomains $unmatchedDomains) {}

    public function record(
        Iri $iri,
        Projection $projection,
        Placement $placement,
        RoutingContext $context,
    ): ?string {
        // Before the ledger insert, and in its own failure envelope: the
        // triage aggregate is the cheaper of the two records, so it must never
        // be able to cost us the one `routing:reproject` replays. Its own
        // internal catch is what makes this safe to call first.
        $this->unmatchedDomains->record($iri, $projection);

        try {
            $id = (string) Str::uuid();

            DB::table('routing.link_observations')->insert([
                'id' => $id,
                'user_id' => $context->user?->id,
                'observed_at' => now(),
                'source' => $context->origin,
                'import_run_id' => $context->importRunId,
                // #PRIV-5: minimiseUrl(), not redactUrl() — raw_url is Scope
                // B (routing.link_observations), a non-secret PII carrier
                // (?utm_content=jane%40example.com survives redactUrl() but
                // not the wider isMinimisable() predicate). No uniqueness
                // constraint rides on this column's exact text.
                'raw_url' => Str::limit(SecretParams::minimiseUrl($iri->raw) ?? '', 2000, ''),
                'canonical_url' => $iri->canonical,
                'registrable_key' => $iri->registrableKey,
                'evidence' => json_encode($this->evidence($iri, $projection)),
                'detector_id' => $projection->detectorId,
                'surface_key' => $projection->surfaceKey,
                'verdict' => $placement->verdict->value,
                'block_reason' => $placement->blockReason,
                'catalog_digest' => CompiledCatalog::digest(),
            ]);

            return $id;
        } catch (\Throwable $e) {
            // A partition that does not exist yet is the one failure worth
            // shouting about — everything else is noise on a diagnostic path.
            Log::warning('routing.observation.write_failed', [
                'error' => $e->getMessage(),
                'registrable_key' => $iri->registrableKey,
            ]);

            return null;
        }
    }

    /**
     * The bounded operational envelope (C1): what the projector saw, so a
     * replay can explain a verdict without re-deriving it.
     *
     * @return array<string, mixed>
     */
    private function evidence(Iri $iri, Projection $projection): array
    {
        return array_filter([
            'path' => $iri->path,
            'query_keys' => array_keys($iri->query),
            'subdomain' => $iri->subdomain,
            'aliased_from' => $iri->aliasedFrom,
            'tenant_scoped' => $iri->tenantScoped ?: null,
            'captures' => $projection->captures ?: null,
            'alternatives' => $projection->alternatives ?: null,
            'rejected' => $iri->rejected,
            'reason' => $projection->reason,
        ], fn ($v) => $v !== null && $v !== []);
    }
}
