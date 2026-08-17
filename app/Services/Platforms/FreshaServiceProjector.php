<?php

namespace App\Services\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Content\FreshaServiceItems;
use App\Services\Platforms\Registry\Platform;

// Composes the public Fresha booking blob (`payload.selection`) from content.*.
//
// SLICE 7 (spec D3 + owner ruling D3a, 2026-08-16). This class used to WRITE
// `site.services` rows and compose the blob back out of them. It no longer
// writes anything: `site.services` is dropped in phase 6, and the Fresha
// service menu already lives in content.* under a `kind = 'connection'`
// source, landed by the ingest connector (slice 3b).
//
// Data model now:
//   • payload.raw.services  — the deduped raw scrape (PRIVATE: the public
//                             resource allowlists only url+selection). Its
//                             remaining job is the VENDOR'S MENU ORDER. It is
//                             no longer the value source for the blob, and
//                             since the services cutover nothing projects a
//                             site.services row from it either.
//   • content.*             — the truth for a service's EXISTENCE and its
//                             VALUES. Read through FreshaServiceItems, which
//                             is 3b's already-shipped reproduction of this
//                             exact nine-key shape and is what the dashboard
//                             (FreshaSelectionResource) already renders.
//   • payload.selection     — {…, services, hiddenServiceIds}. services[] is
//                             composed here; hiddenServiceIds RIDES ON THE
//                             BLOB (see compose()).
//
// Read FreshaServiceItems (`kind = 'connection'`), never ManualServiceItems
// (`kind = 'manual'`). The two-surface rule is structural, not stylistic: a
// Fresha service must never reach the public services section and an
// owner-authored service must never reach the booking surface. Pinned both
// ways by tests/Feature/Content/ServiceTwoSurfaceTest.php.
//
// Suppression and departure keep their meanings, re-expressed:
//   • the owner deletes a service → `content.items.removed_at`, which
//     ProjectionWriter NEVER clears on reappearance, so a later scrape
//     re-offering it cannot resurrect it;
//   • the service leaves Fresha  → `content.source_items.removed_at`, which
//     IS cleared on reappearance, so it restores when it returns.
// That asymmetry is the whole difference between the two, and it is why an
// owner deletion must never be recorded on source_items.
//
// RETIRED by D3a: owner-edited Fresha services ("sync broken" / is_manual).
// An owner's edited PRICE has no representation in content.* — content.offers
// is a set-union COLLECTION and FacetRegistry excludes collections from
// content.manual_overrides by design — so the blob can no longer serve one.
// Measured before the ruling: all 61 live `site.services WHERE source='fresha'`
// rows carry is_manual = false and none carries a non-null non-zero
// price_cents, so the feature was never used. Title/description/duration
// overrides are unaffected: those are singleton facets, they keep working
// through content.manual_overrides, and both surfaces now agree on price.
// Do NOT build an offers override lane to bring this back.
//
// Dedup: Fresha lists a service once per category it appears in — the same
// serviceId can occur several times in one scrape. dedupe() collapses to the
// FIRST occurrence, which is what fixes the vendor's menu order in place.
class FreshaServiceProjector
{
    public function __construct(private readonly FreshaServiceItems $items) {}

    /**
     * Collapse duplicate serviceIds (first occurrence wins) and drop malformed
     * entries. The scrape's order is preserved — it's Fresha's own menu order,
     * and compose() renders the blob in this order.
     *
     * @param  array<int, mixed>  $services
     * @return list<array<string, mixed>>
     */
    public function dedupe(array $services): array
    {
        $seen = [];
        $out = [];
        foreach ($services as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $sid = (string) ($entry['serviceId'] ?? '');
            if ($sid === '') {
                continue;
            }
            if (isset($seen[$sid])) {
                continue;
            }
            $seen[$sid] = true;
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Dedupe a fresh scrape and compose the effective blob from content.*.
     *
     * D3a: this writes NOTHING. It used to upsert one `site.services` row per
     * scraped service inside a Postgres transaction guarded by the
     * `services:{user_id}` advisory lock; the ingest connector now lands those
     * services in content.* and this call is a pure read. The advisory lock
     * went with the writes — the lock existed to serialise `sort_order`
     * appends against the global `services_user_sort_order_uq` partial unique,
     * and there is no append left to serialise. Callers that widened their own
     * lock TTLs to cover this call have been narrowed back to the 10s default
     * (FreshaController::connect()/saveSelection(),
     * FreshaConnectFetch::fetchStorewide()).
     *
     * @param  array<int, mixed>  $rawServices  the scrape output (pre-dedup ok)
     * @param  list<mixed>  $previouslyHidden  hiddenServiceIds from the stored
     *                                         selection. Passed straight to
     *                                         compose(), which prunes it — the
     *                                         hidden list rides on the blob now
     *                                         (content.* has no is_active).
     * @return array{services: list<array<string, mixed>>, hiddenServiceIds: list<string>, raw: list<array<string, mixed>>}
     */
    public function sync(User $user, array $rawServices, array $previouslyHidden = []): array
    {
        $raw = $this->dedupe($rawServices);

        return [...$this->compose($user, $raw, $previouslyHidden), 'raw' => $raw];
    }

    /**
     * The effective blob pieces, read live from content.*.
     *
     * Each entry is FreshaServiceItems' nine-key reproduction — the same shape,
     * from the same read, that the dashboard has rendered since 3b. The stored
     * `payload.raw` no longer supplies VALUES, only ORDER: an item listed in it
     * takes that position (Fresha's own menu order), and a live item the stored
     * scrape has not caught up with appends after them rather than waiting for
     * the next refresh to become visible.
     *
     * Existence is content.*'s answer alone, which is what carries suppression
     * and departure across the cutover: an owner-deleted service has
     * `content.items.removed_at` set and an absent one has
     * `content.source_items.removed_at` set, and FreshaServiceItems filters on
     * both, so neither reaches the blob. The difference between them lives in
     * ProjectionWriter (it clears the second on reappearance and never the
     * first), not here.
     *
     * @param  array<int, mixed>  $rawServices  the stored payload.raw services — ORDER only
     * @param  list<mixed>  $hidden  the stored selection's own hiddenServiceIds.
     *                               There is no is_active in content.*, so this
     *                               is carried on the blob (where
     *                               FreshaSelectionResource already reads it)
     *                               and merely PRUNED here to ids that are still
     *                               live — the same "never drift stale" rule
     *                               FreshaFetch applies to it.
     * @return array{services: list<array<string, mixed>>, hiddenServiceIds: list<string>}
     */
    public function compose(User $user, array $rawServices, array $hidden = []): array
    {
        $live = [];
        foreach ($this->items->selectionServices((string) $user->id) as $entry) {
            $sid = (string) ($entry['serviceId'] ?? '');
            if ($sid !== '' && ! isset($live[$sid])) {
                $live[$sid] = $entry;
            }
        }

        $services = [];
        $seen = [];
        foreach ($this->dedupe($rawServices) as $entry) {
            $sid = (string) $entry['serviceId'];
            if (! isset($live[$sid]) || isset($seen[$sid])) {
                continue;
            }
            $seen[$sid] = true;
            $services[] = $live[$sid];
        }
        foreach ($live as $sid => $entry) {
            if (! isset($seen[$sid])) {
                $services[] = $entry;
            }
        }

        $hiddenIds = [];
        foreach ($hidden as $id) {
            if (is_string($id) && isset($live[$id]) && ! in_array($id, $hiddenIds, true)) {
                $hiddenIds[] = $id;
            }
        }

        return ['services' => $services, 'hiddenServiceIds' => $hiddenIds];
    }

    /**
     * Recompose selection.services on the stored Fresha connection from
     * content.* — called after any owner write that could change what the
     * booking menu shows. No-ops for legacy connections (no payload.raw yet)
     * and when nothing changed. Saving the model fires
     * IntegrationConnectionObserver's public edge purge.
     *
     * The stored hiddenServiceIds is fed back in rather than re-derived: it is
     * the owner's own curation and content.* has no is_active to derive it
     * from. compose() prunes it to ids that are still live, so a service the
     * owner hid and then deleted does not leave a dangling id behind.
     */
    public function refreshBlob(User $user): void
    {
        $connection = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('platform', Platform::Fresha->value)
            ->first();
        if ($connection === null) {
            return;
        }

        $payload = is_array($connection->payload) ? $connection->payload : [];
        $selection = $payload['selection'] ?? null;
        $rawServices = $payload['raw']['services'] ?? null;
        if (! is_array($selection) || ! is_array($rawServices)) {
            return;
        }

        $storedHidden = is_array($selection['hiddenServiceIds'] ?? null) ? $selection['hiddenServiceIds'] : [];

        $composed = $this->compose($user, $rawServices, $storedHidden);
        $inner = [
            ...$selection,
            'services' => $composed['services'],
            'hiddenServiceIds' => $composed['hiddenServiceIds'],
        ];
        if ($inner == $selection) {
            return;
        }

        $connection->payload = [...$payload, 'selection' => $inner];
        $connection->save();
    }

    /**
     * Toggle one service's hidden state on the stored blob. The hidden list IS
     * the record (D3a — content.* has no is_active); compose() prunes it to
     * live ids. The dashboard's is_active toggle maps here.
     */
    public function setHidden(User $user, string $serviceId, bool $hidden): void
    {
        $connection = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('platform', Platform::Fresha->value)
            ->first();
        $payload = is_array($connection?->payload) ? $connection->payload : [];
        $selection = $payload['selection'] ?? null;
        if ($connection === null || ! is_array($selection)) {
            return;
        }

        $list = array_values(array_filter($selection['hiddenServiceIds'] ?? [], 'is_string'));
        $list = $hidden
            ? array_values(array_unique([...$list, $serviceId]))
            : array_values(array_filter($list, fn ($id) => $id !== $serviceId));

        $rawServices = is_array($payload['raw']['services'] ?? null) ? $payload['raw']['services'] : [];
        $composed = $this->compose($user, $rawServices, $list);
        $connection->payload = [...$payload, 'selection' => [
            ...$selection,
            'services' => $composed['services'],
            'hiddenServiceIds' => $composed['hiddenServiceIds'],
        ]];
        $connection->save();
    }

    /** "1h 15min" / "45min" / "2h" → minutes; null when unparsable. */
    public function parseDuration(mixed $display): ?int
    {
        if (! is_string($display) || trim($display) === '') {
            return null;
        }
        $minutes = 0;
        if (preg_match('/(\d+)\s*h(?:r|our)?s?/i', $display, $m)) {
            $minutes += ((int) $m[1]) * 60;
        }
        if (preg_match('/(\d+)\s*m(?:in)?(?:ute)?s?/i', $display, $m)) {
            $minutes += (int) $m[1];
        }

        return $minutes > 0 ? $minutes : null;
    }
}
