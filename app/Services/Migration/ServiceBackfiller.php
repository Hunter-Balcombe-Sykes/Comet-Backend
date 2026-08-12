<?php

namespace App\Services\Migration;

use App\Ingest\Projection\ProjectionWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Slice 3a §3.1: land the owner-authored services as service-kind content
 * items, through the slice-0b manual lane — NEVER raw writes into content.*.
 *
 * Coord manual:{service_uuid}: stable, so a re-run UPDATES (idempotent), and
 * the legacy identifier survives site.services' drop in slice 7. Stable
 * because the owner-authored write path updates rows in place — unlike
 * ShopCatalog::syncLatest(), which deletes and re-creates so shop uuids churn
 * every sync (slice 5a).
 *
 * Fresha-sourced rows are deliberately OUT of scope (§2): once 3b fixes the
 * connector they land natively under the Fresha source with real prices, and
 * backfilling them here would stamp owner-authorship on scraped data —
 * destroying the discriminator the two public surfaces key on.
 */
class ServiceBackfiller
{
    public function __construct(private readonly ProjectionWriter $writer) {}

    /** @return array{backfilled: int, retired: int, skipped_no_user: int, failed: int} */
    public function run(bool $dryRun = false, ?string $userId = null): array
    {
        $result = ['backfilled' => 0, 'retired' => 0, 'skipped_no_user' => 0, 'failed' => 0];

        $rows = DB::connection('pgsql')->table('site.services')
            ->whereNull('source')
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->orderBy('user_id')
            ->orderBy('sort_order')
            ->get();

        foreach ($rows as $service) {
            try {
                $owner = $service->user_id === null ? null : (string) $service->user_id;
                if ($owner === null) {
                    // Loud rather than silent: a skipped row is a service that
                    // vanishes from the count without anyone deciding to drop it.
                    $result['skipped_no_user']++;

                    continue;
                }

                $isDeleted = $service->deleted_at !== null;

                if ($dryRun) {
                    $isDeleted ? $result['retired']++ : $result['backfilled']++;

                    continue;
                }

                $this->writer->writeManualItem($owner, 'manual:'.$service->id, $this->projectionFor($service));

                if ($isDeleted) {
                    // items.removed_at ONLY. source_items.removed_at is cleared
                    // on reappearance, so a later run would resurrect a service
                    // its owner deleted.
                    $this->retire($owner, 'manual:'.$service->id, $service->deleted_at);
                    $result['retired']++;

                    continue;
                }

                $result['backfilled']++;
            } catch (\Throwable $e) {
                report($e);
                Log::warning('Service backfill failed for one row.', [
                    'service_id' => $service->id, 'error' => $e->getMessage(),
                ]);
                $result['failed']++;
            }
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function projectionFor(object $service): array
    {
        $title = trim((string) ($service->title ?? ''));
        $description = trim((string) ($service->description ?? ''));

        $projection = [
            'kind' => 'service',
            'headline' => $title,
            'facets' => ['f_text' => array_filter([
                'headline' => $title !== '' ? $title : null,
                'body' => $description !== '' ? $description : null,
            ], static fn ($v) => $v !== null)],
        ];

        if ($service->duration_minutes !== null) {
            $projection['facets']['f_duration'] = ['seconds' => ((int) $service->duration_minutes) * 60];
        }

        if ($service->price_cents !== null) {
            $cents = (int) $service->price_cents;
            $projection['offers'] = [[
                // §1.2: a HAND-ENTERED zero means free. Scraped zeros are 3b's
                // problem and must not be routed through this mapper.
                'qualifier' => $cents === 0 ? 'free' : 'exact',
                'amount_minor' => $cents === 0 ? null : $cents,
                'currency' => $cents === 0 ? null : (string) $service->currency_code,
            ]];
        }

        return $projection;
    }

    /** Retire the item behind a coord — items.removed_at only, never source_items. */
    private function retire(string $userId, string $coord, mixed $deletedAt): void
    {
        $itemId = DB::connection('pgsql')->table('content.source_items as si')
            ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
            ->where('cs.user_id', $userId)
            ->where('si.coord', $coord)
            ->value('si.item_id');

        if ($itemId === null) {
            return;
        }

        DB::connection('pgsql')->table('content.items')
            ->where('id', $itemId)
            ->whereNull('removed_at')
            ->update(['removed_at' => $deletedAt, 'updated_at' => now()]);
    }
}
