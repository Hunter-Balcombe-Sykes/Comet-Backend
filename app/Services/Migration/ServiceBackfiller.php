<?php

namespace App\Services\Migration;

use App\Models\Core\Site\Site;
use App\Services\Content\ManualServiceWriter;
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
 *
 * The projection mapping, pin/exclude curation and three-lane invalidation
 * live in ManualServiceWriter, shared with UserServiceController's write
 * cutover (Task 5) — one mapping, not two independently-drifting copies.
 */
class ServiceBackfiller
{
    public function __construct(
        private readonly ManualServiceWriter $manual,
    ) {}

    /** @return array{backfilled: int, retired: int, skipped_no_user: int, failed: int} */
    public function run(bool $dryRun = false, ?string $userId = null): array
    {
        $result = ['backfilled' => 0, 'retired' => 0, 'skipped_no_user' => 0, 'failed' => 0];
        $touchedSites = [];

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

                $itemId = $this->manual->write($owner, 'manual:'.$service->id, $this->manual->projectionFor($service));

                if ($isDeleted) {
                    // items.removed_at ONLY. source_items.removed_at is cleared
                    // on reappearance, so a later run would resurrect a service
                    // its owner deleted.
                    $this->manual->markRemoved($itemId, $service->deleted_at);
                    $result['retired']++;

                    continue;
                }

                $site = Site::query()->where('user_id', $owner)->first();
                if ($site !== null) {
                    // §3.1: is_active=false has no content.items equivalent —
                    // it maps to a pool EXCLUDE, not a pin. A live-but-hidden
                    // service must not surface in buildServicesData() (which
                    // filters excluded state) or gate Services/Booking
                    // visibility on.
                    $isActive = (bool) ($service->is_active ?? true);
                    $isActive
                        ? $this->manual->pin($site, $itemId, (float) ($service->sort_order ?? 0))
                        : $this->manual->exclude($site, $itemId);
                    $touchedSites[(string) $site->id] = true;
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

        if (! $dryRun) {
            $this->manual->invalidate(array_keys($touchedSites));
        }

        return $result;
    }
}
