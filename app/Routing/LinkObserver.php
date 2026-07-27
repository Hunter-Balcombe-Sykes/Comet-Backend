<?php

namespace App\Routing;

use App\Catalog\CompiledCatalog;
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
    public function record(
        Iri $iri,
        Projection $projection,
        Placement $placement,
        RoutingContext $context,
    ): ?string {
        try {
            $id = (string) Str::uuid();

            DB::table('routing.link_observations')->insert([
                'id' => $id,
                'user_id' => $context->user?->id,
                'observed_at' => now(),
                'source' => $context->origin,
                'import_run_id' => $context->importRunId,
                'raw_url' => Str::limit($iri->raw, 2000, ''),
                'canonical_url' => $iri->canonical,
                'registrable_key' => $iri->registrableKey,
                'evidence' => json_encode($this->evidence($iri, $projection)),
                'detector_id' => $projection->detectorId,
                'surface_key' => $projection->surfaceKey,
                'confidence' => $projection->matched() ? $projection->confidence : null,
                'margin' => $projection->matched() ? $projection->margin : null,
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
