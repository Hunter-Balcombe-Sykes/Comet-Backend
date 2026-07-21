<?php

namespace App\Services\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\Service;
use App\Models\Core\User\ServiceCategory;
use App\Models\Core\User\User;
use App\Services\Platforms\Registry\Platform;
use Illuminate\Support\Facades\DB;

// Projects Fresha's scraped service menu into REAL site.services rows and
// composes the public booking blob back FROM those projections — the bridge
// that makes Fresha services behave like menu items (editable, revertible,
// deduped) without changing the public CDN contract.
//
// Data model:
//   • payload.raw.services   — the deduped raw scrape (PRIVATE: the public
//                              resource allowlists only url+selection). The
//                              revert source, and the compose() input.
//   • site.services rows     — source='fresha', external_id=serviceId. The
//                              editable truth. is_manual=TRUE marks an owner
//                              edit ("sync broken"): sync() never overwrites
//                              such a row; revert() re-projects it from raw.
//                              is_active mirrors the public hidden toggle.
//   • payload.selection      — {services, hiddenServiceIds} COMPOSED from the
//                              projections: a synced row contributes its raw
//                              entry verbatim; a detached row contributes the
//                              serialized owner version. Ships to the public
//                              CDN exactly as before (partna-pages unchanged).
//
// Suppression: deleting a projected row soft-deletes it with
// deleted_origin='user' — sync() skips re-creating that serviceId forever
// (PurgeSoftDeleted excludes these rows). A service that disappears from
// Fresha soft-deletes with deleted_origin='sync' and restores when it returns.
// Detached (is_manual) rows are OWNER content: they survive a Fresha removal
// and keep serializing into the blob.
//
// Dedup: Fresha lists a service once per category it appears in — the same
// serviceId can occur several times in one scrape. dedupe() collapses to the
// FIRST occurrence (its category label wins, matching the menu convention).
//
// Legacy connections (created before this projector) carry no payload.raw —
// every consumer here no-ops for them until their next refresh / reconnect /
// selection save populates projections + raw. Until then the stored blob
// behaves exactly as before.
class FreshaServiceProjector
{
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
     * Every category label a serviceId is listed under, in scrape order —
     * a duplicate listing means ONE service in SEVERAL categories, so the
     * projection unions the labels into memberships.
     *
     * @param  array<int, mixed>  $services  the pre-dedup scrape
     * @return array<string, list<string>> serviceId => unique labels
     */
    private function categoryLabelsByServiceId(array $services): array
    {
        $labels = [];
        foreach ($services as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $sid = (string) ($entry['serviceId'] ?? '');
            $label = is_string($entry['category'] ?? null) ? trim((string) $entry['category']) : '';
            if ($sid === '' || $label === '') {
                continue;
            }
            if (! in_array($label, $labels[$sid] ?? [], true)) {
                $labels[$sid][] = $label;
            }
        }

        return $labels;
    }

    /**
     * Upsert projections from a fresh scrape, then compose the effective blob.
     *
     * @param  array<int, mixed>  $rawServices  the scrape output (pre-dedup ok)
     * @param  list<mixed>  $previouslyHidden  hiddenServiceIds from the stored
     *                                         selection — seeds is_active on rows
     *                                         projected for the FIRST time (a
     *                                         legacy connection's curation carries
     *                                         over); existing rows keep their own
     *                                         is_active.
     * @return array{services: list<array<string, mixed>>, hiddenServiceIds: list<string>, raw: list<array<string, mixed>>}
     */
    public function sync(User $user, array $rawServices, array $previouslyHidden = []): array
    {
        $raw = $this->dedupe($rawServices);
        $labelsById = $this->categoryLabelsByServiceId($rawServices);
        $hiddenSet = array_flip(array_values(array_filter($previouslyHidden, 'is_string')));

        DB::connection('pgsql')->transaction(function () use ($user, $raw, $labelsById, $hiddenSet) {
            // Same advisory key as InsertWithSortOrder's manual-service create —
            // the global (user_id, sort_order) partial unique means every
            // sort_order append must serialize behind one lock.
            DB::connection('pgsql')->select('select pg_advisory_xact_lock(hashtext(?))', ["services:{$user->id}"]);

            $existing = Service::withTrashed()
                ->where('user_id', $user->id)
                ->where('source', 'fresha')
                ->get()
                ->keyBy(fn (Service $s) => (string) $s->external_id);

            $max = Service::query()
                ->where('user_id', $user->id)
                ->whereNull('deleted_at')
                ->max('sort_order');
            $next = is_null($max) ? 0 : ((int) $max + 1);

            $seen = [];
            foreach ($raw as $entry) {
                $sid = (string) $entry['serviceId'];
                $seen[$sid] = true;
                $row = $existing->get($sid);

                $categoryIds = $this->resolveCategoryIds($user, $labelsById[$sid] ?? []);

                if ($row === null) {
                    // SEC-1: relation ->create() sets user_id via the FK (not
                    // mass-assignable).
                    $created = $user->services()->create([
                        'source' => 'fresha',
                        'external_id' => $sid,
                        'is_manual' => false,
                        'is_active' => ! isset($hiddenSet[$sid]),
                        'sort_order' => $next++,
                        ...$this->rawAttributes($user, $entry),
                    ]);
                    $created->categories()->sync($categoryIds);

                    continue;
                }

                if ($row->trashed()) {
                    // The owner deleted this service — suppressed, never resurrected.
                    if ($row->deleted_origin === 'user') {
                        continue;
                    }
                    // Departed-then-returned: restore with fresh fields and a fresh
                    // sort slot (its old slot may have been reclaimed — the partial
                    // unique index only covers live rows).
                    $row->forceFill([
                        ...$this->rawAttributes($user, $entry),
                        'is_manual' => false,
                        'sort_order' => $next++,
                        'deleted_origin' => null,
                        'deleted_at' => null,
                    ])->save();
                    $row->categories()->sync($categoryIds);

                    continue;
                }

                // Detached (owner-edited) rows are owner content — never overwritten
                // (fields OR memberships: the owner may have re-categorised it).
                if ($row->is_manual) {
                    continue;
                }

                $row->forceFill($this->rawAttributes($user, $entry))->save();
                $row->categories()->sync($categoryIds);
            }

            // Departed: live synced rows whose serviceId vanished from the scrape.
            // Detached rows survive (owner content).
            foreach ($existing as $sid => $row) {
                if (isset($seen[$sid]) || $row->trashed() || $row->is_manual) {
                    continue;
                }
                $row->deleted_origin = 'sync';
                $row->saveQuietly();
                $row->delete();
            }
        });

        return [...$this->compose($user, $raw), 'raw' => $raw];
    }

    /**
     * The effective blob pieces from the CURRENT projections — no upserts.
     * A synced row contributes its raw entry verbatim (byte-stable public
     * payload); a detached row contributes the serialized owner version;
     * a suppressed/deleted serviceId contributes nothing. Detached rows whose
     * service left Fresha entirely append at the end.
     *
     * @param  array<int, mixed>  $rawServices  the stored payload.raw services
     * @return array{services: list<array<string, mixed>>, hiddenServiceIds: list<string>}
     */
    public function compose(User $user, array $rawServices): array
    {
        $raw = $this->dedupe($rawServices);

        $rows = Service::query()
            ->where('user_id', $user->id)
            ->where('source', 'fresha')
            ->with('categories:id,title')
            ->get()
            ->keyBy(fn (Service $s) => (string) $s->external_id);

        $services = [];
        $inRaw = [];
        foreach ($raw as $entry) {
            $sid = (string) $entry['serviceId'];
            $inRaw[$sid] = true;
            $row = $rows->get($sid);
            if ($row === null) {
                continue; // suppressed or deleted — gone from the public list too
            }
            $services[] = $row->is_manual ? $this->serialize($row, $entry) : $entry;
        }
        foreach ($rows as $sid => $row) {
            if (! isset($inRaw[$sid]) && $row->is_manual) {
                // Owner-edited row whose service left Fresha — still the owner's
                // content, still listed.
                $services[] = $this->serialize($row, null);
            }
        }

        $hidden = $rows
            ->filter(fn (Service $s) => ! $s->is_active)
            ->map(fn (Service $s) => (string) $s->external_id)
            ->values()
            ->all();

        return ['services' => $services, 'hiddenServiceIds' => $hidden];
    }

    /**
     * Recompose selection.services / hiddenServiceIds on the stored Fresha
     * connection from the current projections — called after any owner write
     * to a projected row (edit / delete / restore / revert / visibility).
     * No-ops for legacy connections (no payload.raw yet) and when nothing
     * changed. Saving the model fires IntegrationConnectionObserver's public
     * edge purge.
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

        $composed = $this->compose($user, $rawServices);
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
     * Re-project one detached row from the stored raw scrape ("revert to
     * synced"). Returns false when the connection carries no raw entry for it
     * (the service no longer exists on Fresha — nothing to revert to).
     */
    public function revert(User $user, Service $service): bool
    {
        $entry = $this->rawEntryFor($user, (string) $service->external_id);
        if ($entry === null) {
            return false;
        }

        $service->forceFill([
            ...$this->rawAttributes($user, $entry),
            'is_manual' => false,
        ])->save();

        return true;
    }

    /** The stored raw scrape entry for one serviceId, or null. */
    private function rawEntryFor(User $user, string $externalId): ?array
    {
        $payload = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('platform', Platform::Fresha->value)
            ->value('payload');
        $rawServices = is_array($payload) ? ($payload['raw']['services'] ?? null) : null;
        if (! is_array($rawServices)) {
            return null;
        }

        foreach ($rawServices as $entry) {
            if (is_array($entry) && (string) ($entry['serviceId'] ?? '') === $externalId) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Service-row attributes projected from one raw scrape entry (everything
     * except identity/provenance/ordering, which the caller owns).
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function rawAttributes(User $user, array $entry): array
    {
        $priceValue = $entry['priceValue'] ?? null;
        $currency = is_string($entry['currency'] ?? null) && strlen((string) $entry['currency']) === 3
            ? strtoupper((string) $entry['currency'])
            : 'AUD';

        return [
            'title' => (string) ($entry['name'] ?? ''),
            'description' => is_string($entry['description'] ?? null) && trim($entry['description']) !== ''
                ? trim($entry['description'])
                : null,
            'price_cents' => is_numeric($priceValue) ? (int) round(((float) $priceValue) * 100) : 0,
            'currency_code' => $currency,
            'duration_minutes' => $this->parseDuration($entry['duration'] ?? null),
        ];
    }

    /**
     * The blob-shaped entry for a DETACHED row — same keys the scraper emits,
     * so partna-pages renders owner edits with zero changes. $rawEntry (when
     * the service still exists on Fresha) fills the fields a Service row
     * doesn't model (hasVariants, and duration/category fallbacks).
     *
     * @param  array<string, mixed>|null  $rawEntry
     * @return array<string, mixed>
     */
    private function serialize(Service $row, ?array $rawEntry): array
    {
        return [
            'serviceId' => (string) $row->external_id,
            'name' => (string) $row->title,
            'duration' => $this->formatDuration($row->duration_minutes) ?? ($rawEntry['duration'] ?? null),
            'description' => $row->description,
            'price' => $this->formatPrice((int) $row->price_cents, (string) $row->currency_code),
            'priceValue' => round(((int) $row->price_cents) / 100, 2),
            'currency' => (string) $row->currency_code,
            'category' => $row->categories->first()?->title ?? ($rawEntry['category'] ?? null),
            'hasVariants' => (bool) ($rawEntry['hasVariants'] ?? false),
        ];
    }

    /**
     * find-or-create the user's ServiceCategory rows for a set of Fresha
     * category labels (matched live, case-insensitive) — the MEMBERSHIP ids a
     * projected service syncs to. A TRASHED same-title category means the
     * owner deleted it — never resurrected; that label contributes nothing.
     *
     * @param  list<string>  $labels
     * @return list<string>
     */
    private function resolveCategoryIds(User $user, array $labels): array
    {
        $ids = [];
        foreach ($labels as $label) {
            $title = trim((string) $label);
            if ($title === '') {
                continue;
            }
            $needle = mb_strtolower($title);

            $live = ServiceCategory::query()
                ->where('user_id', $user->id)
                ->get(['id', 'title'])
                ->first(fn (ServiceCategory $c) => mb_strtolower(trim((string) $c->title)) === $needle);
            if ($live !== null) {
                $ids[] = (string) $live->id;

                continue;
            }

            $trashedExists = ServiceCategory::onlyTrashed()
                ->where('user_id', $user->id)
                ->get(['id', 'title'])
                ->contains(fn (ServiceCategory $c) => mb_strtolower(trim((string) $c->title)) === $needle);
            if ($trashedExists) {
                continue;
            }

            $max = ServiceCategory::query()->where('user_id', $user->id)->max('sort_order');
            // SEC-1: relation ->create() sets user_id via the FK (not mass-assignable).
            $ids[] = (string) $user->serviceCategories()->create([
                'title' => $title,
                'sort_order' => is_null($max) ? 0 : ((int) $max + 1),
                'source' => 'fresha',
            ])->id;
        }

        return array_values(array_unique($ids));
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

    /** minutes → "1h 15min" / "45min" / "2h"; null passthrough. */
    public function formatDuration(?int $minutes): ?string
    {
        if ($minutes === null || $minutes <= 0) {
            return null;
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        if ($h > 0 && $m > 0) {
            return "{$h}h {$m}min";
        }

        return $h > 0 ? "{$h}h" : "{$m}min";
    }

    /** cents + ISO code → Fresha-style display price ("A$65", "A$42.50"). */
    public function formatPrice(int $cents, string $currencyCode): string
    {
        $symbols = ['AUD' => 'A$', 'NZD' => 'NZ$', 'USD' => '$', 'GBP' => '£', 'EUR' => '€'];
        $code = strtoupper($currencyCode);
        $amount = $cents / 100;
        $formatted = $amount == floor($amount)
            ? number_format($amount, 0)
            : number_format($amount, 2);

        return isset($symbols[$code]) ? $symbols[$code].$formatted : "{$code} {$formatted}";
    }
}
