<?php

namespace App\Routing;

use App\Catalog\CatalogNotCompiled;
use App\Catalog\CompiledCatalog;
use App\Catalog\LegacyPlatformMap;
use App\Models\Core\User\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * B4 (p8 deletion readiness): the findings / synced-modal contract.
 *
 * The new pipeline records a scan-discovered conflict as a HOLD intent
 * (`routing.source_intents`, state=blocked, block_reason=conflict) — but the
 * dashboard's existing Instagram modal reads `payload.syncFindings` via
 * GET /platforms/instagram/synced. Without this fold, migrating
 * LinkInBioScanJob / InstagramAutoSync onto the new router would succeed
 * mechanically and silently stop telling users about conflicts.
 *
 * This bridge folds those Hold intents into the findings RESPONSE at read
 * time (never into the stored payload — one source of truth, no writer to
 * un-ship later), shaped exactly like a legacy conflict finding so the modal
 * renders them unchanged. Scoped by origin: only the bio-scan origins the
 * Instagram modal owns; other scan surfaces fold their own origins when they
 * migrate.
 */
class SyncFindingsBridge
{
    /** Origins whose scans surface in the Instagram synced modal. */
    public const BIO_ORIGINS = ['bio_harvest', 'link_in_bio'];

    /**
     * Hold-intent conflicts shaped as legacy findings ({platform, category,
     * label, status:'conflict', foundUrl, removePath}).
     *
     * @return list<array<string, mixed>>
     */
    public function conflictFindings(User $user): array
    {
        return $this->conflicts($user)
            ->map(fn (object $intent): array => $this->shape($intent))
            ->all();
    }

    /** The Hold intent behind a folded finding, looked up by its legacy platform slug. */
    public function findConflict(User $user, string $legacyPlatform): ?object
    {
        return $this->conflicts($user)
            ->first(fn (object $intent) => LegacyPlatformMap::legacyFor($intent->surface_key) === $legacyPlatform);
    }

    /** @return Collection<int, object> */
    private function conflicts(User $user): Collection
    {
        return DB::table('routing.source_intents')
            ->where('user_id', $user->id)
            ->where('state', 'blocked')
            ->where('block_reason', 'conflict')
            ->whereIn('origin', self::BIO_ORIGINS)
            ->orderByDesc('first_seen_at')
            ->get()
            ->values();
    }

    /** @return array<string, mixed> */
    private function shape(object $intent): array
    {
        try {
            $surface = CompiledCatalog::surface($intent->surface_key);
        } catch (CatalogNotCompiled) {
            $surface = null;
        }

        return [
            'platform' => LegacyPlatformMap::legacyFor($intent->surface_key),
            'category' => $this->legacyCategory((string) $intent->routing_class),
            'label' => $surface['display_name'] ?? $intent->surface_key,
            'status' => 'conflict',
            'foundUrl' => $intent->canonical_url,
            'removePath' => null,
        ];
    }

    /** The modal speaks the legacy category vocabulary, not routing classes. */
    private function legacyCategory(string $routingClass): string
    {
        return match ($routingClass) {
            'ordering' => 'online-ordering',
            'events' => 'event',
            'content', 'link' => 'other',
            default => $routingClass,
        };
    }
}
