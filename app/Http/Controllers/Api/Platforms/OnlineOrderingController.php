<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Catalog\CompiledCatalog;
use App\Catalog\LegacyPlatformMap;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Jobs\Content\EnrichPoolLinkJob;
use App\Jobs\Platforms\EnrichLinkCardJob;
use App\Jobs\Platforms\MenuFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Content\LinkPoolWriter;
use App\Services\Platforms\LinkCardScraper;
use App\Services\Platforms\LinkRouter;
use App\Services\Platforms\Payloads\CardPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

// Online ordering — a multi-entry category. The user pastes one or more
// ordering links (Uber Eats, DoorDash, a direct order page); each is fetched
// once and snapshotted into a branded card (one row per entry, resource_id
// 'order-<hash>'). Google Business auto-seeds entries here too
// (source:'google-business') carrying the metadata Google gives us
// (delivery/pickup type, fees, est. time, platform name) under `data`.
//
// Convergence Phase 6: an entry is no longer one `partna.order_link` row. Every
// ordering link now carries its own BRAND surface (`uber_eats.order`,
// `doordash.order`, `bopple.order`, …) so ingest can see it, which changes two
// things here and nothing else:
//
//   - Reads scope on routing_class 'ordering', not the retired platform slug.
//     See ManagesIntegrationConnection::routingClass() for why that axis.
//   - Writes delegate to LinkRouter, which already writes per-brand and already
//     enforces the owner's one-store-per-brand ruling. A URL whose brand has no
//     home — an unrecognised ordering page, or a second store for a brand the
//     user already has — becomes a links-pool item carrying its provider label
//     (owner ruling 2A, 2026-08-16) rather than being dropped.
//
// The wire shape is unchanged, with one addition: a link that went to the pool
// answers `routedTo: {pool: 'custom_links'}` so the dashboard can say where it
// landed instead of showing an ordering list the entry isn't in.
//
// Dashboard-only: these rows never reach the public sitepage as integrations
// (excluded by PublicIntegrationController). They DO drive the public "Order
// online" actions — SiteActionsService::pool() reads the same routing class.
class OnlineOrderingController extends ApiController
{
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    private const MAX_ENTRIES = 10;

    public function __construct(
        private readonly LinkCardScraper $scraper,
        private readonly LinkRouter $router,
        private readonly LinkPoolWriter $pool,
    ) {}

    // The FAMILY key: what the per-user lock and FeatureAvailability key on.
    // Deliberately still the legacy slug — those two are per-family concerns and
    // the family did not change, only the storage surface underneath it.
    protected function platform(): string
    {
        return 'online-ordering';
    }

    protected function routingClass(): ?string
    {
        return 'ordering';
    }

    // GET /api/platforms/online-ordering/entries
    public function entries(Request $request): JsonResponse
    {
        return $this->success(['entries' => $this->entriesData($this->currentUser($request))]);
    }

    // POST /api/platforms/online-ordering/entries — attach an ordering link.
    // Returns 202 immediately with a minimal card; EnrichLinkCardJob upgrades
    // name/logo/favicon off-thread once the HTTP fetch completes (JOB-1).
    // Merge-on-add / MAX_ENTRIES / storeKey logic runs synchronously — all
    // URL-derived, no slow fetch needed.
    public function addEntry(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        // Sector-derived gate (2026-07-15): online ordering is a food-business
        // feature (partna lost it entirely — explicit owner override). Reads
        // and disconnect (entries()/entryStatus()/removeEntry()/forget()) stay open.
        if (! AccountCapabilities::for($user)->can_use_online_ordering) {
            return $this->error('Online ordering is not available for your account.', 403);
        }

        $validated = $request->validate(['url' => ['required', 'string', 'max:1000']]);

        $url = $this->scraper->normalizeUrl($validated['url']);
        if (! $url) {
            return $this->error('Enter a valid link (https://...).', 422);
        }
        // Minimal card only — the slow metadata fetch moves to EnrichLinkCardJob (JOB-1).
        $meta = ['url' => $url, ...$this->scraper->minimalCard($url)];

        // The mode the URL itself declares (Uber Eats ?diningMode=PICKUP|DELIVERY),
        // used to slot a merge-on-add into the right pickup/delivery URL.
        $mode = $this->modeOf($meta['url']);
        $storeKey = $this->storeKey($meta['url']);

        // PWL-D2: EnrichLinkCardJob/MenuFetchJob dispatch under QUEUE_CONNECTION=
        // sync run INLINE — dispatching either from inside the lock closure would
        // hold the 10s lock across that inline work. Capture what the write
        // settled on and dispatch only after the lock releases, gated on the
        // write having actually succeeded (never on the 422 cap or a 423 lock
        // timeout) — mirrors the fix already in removeEntry()/forget() below.
        $rid = null;
        $surfaceKey = null;
        $pooled = false;
        $response = $this->withConnectionLock($user, function () use ($user, $url, $meta, $mode, $storeKey, &$rid, &$surfaceKey, &$pooled) {
            // Merge-on-add runs FIRST, and deliberately before any routing: a
            // link to a store the user already has (same host + store path)
            // folds into that existing row — one store = one entry carrying both
            // a pickup and a delivery URL — whatever brand surface that row now
            // sits on. Routing first would send the second mode of a store the
            // user already has to the links pool, because LinkRouter reads a
            // different url for an occupied brand as a SECOND store (ruling 1).
            // Two URLs with the same storeKey are one store by definition.
            $existing = $storeKey === null ? null : $this->entryRows($user)
                ->first(fn (IntegrationConnection $row) => $this->storeKey(CardPayload::fromArray($row->payload)->url()) === $storeKey);

            if ($existing) {
                $rid = $existing->resource_id;
                $surfaceKey = (string) $existing->surface_key;
                $this->mergeEntryCard($user, $existing, $this->mergeStorePayload(CardPayload::fromArray($existing->payload)->toArray(), $meta, $mode));

                return $this->success([
                    'status' => 'pending',
                    'entries' => $this->entriesData($user),
                    'statusUrl' => url("/api/platforms/online-ordering/entries/{$rid}/status"),
                ], 202);
            }

            if ($this->entryRows($user)->count() >= self::MAX_ENTRIES) {
                return $this->error('You can add up to '.self::MAX_ENTRIES.' ordering links.', 422);
            }

            // A NEW store. LinkRouter owns both decisions this controller used to
            // make implicitly: which brand surface the link belongs to, and
            // whether that brand's single store slot is already taken.
            $routed = $this->router->routeOrdering($user, $url);

            if ($routed->outcome !== 'seeded') {
                // No brand home — an unrecognised ordering page, or a second
                // store for a brand the user already has. Owner ruling 2A: it
                // becomes a links-pool item carrying its provider label, rather
                // than a row on a surface ingest cannot read. Written outside a
                // connection entirely, so `entries` legitimately does not list it.
                $pooled = true;
                $this->pool->add($user, $url, $meta['name'] ?? null, $meta['description'] ?? null);

                return $this->success([
                    'status' => 'ok',
                    'routedTo' => ['pool' => LinkPoolWriter::POOL],
                    'entries' => $this->entriesData($user),
                ], 202);
            }

            $rid = $routed->resourceId;
            $surfaceKey = LegacyPlatformMap::surfaceFor($routed->platform) ?? $routed->platform;

            // routeOrdering() wrote the router's own auto-seeded card. Replace it
            // with this endpoint's manual card so the wire shape is byte-identical
            // to what it always was — provider 'custom', source 'manual', the
            // scraped display fields, the URL's declared pickup/delivery mode.
            // The brand identity is no longer carried in the payload at all; it
            // is the row's surface_key, which is the whole point of the move.
            $row = $this->connectionFor($user, $rid);
            if ($row !== null) {
                $this->mergeEntryCard($user, $row, $this->mergeStorePayload([
                    'id' => $rid,
                    'provider' => 'custom',
                    'source' => 'manual',
                    ...$meta,
                    // The catalog's display name stands in when the page gave us
                    // no title — "Uber Eats" beats the bare host.
                    'name' => $meta['name'] ?? $this->brandLabel($surfaceKey),
                ], $meta, $mode));
            }

            return $this->success([
                'status' => 'pending',
                'entries' => $this->entriesData($user),
                'statusUrl' => url("/api/platforms/online-ordering/entries/{$rid}/status"),
            ], 202);
        });

        if ($response->getStatusCode() === 202) {
            if ($pooled) {
                EnrichPoolLinkJob::dispatch((string) $user->id, $url)->afterCommit();
            } elseif ($rid !== null) {
                EnrichLinkCardJob::dispatch((string) $user->id, $this->platform(), $rid, $url, $surfaceKey)->afterCommit();
            }
            // Ordering links drive the shared menu — (re)derive it from them.
            MenuFetchJob::dispatch((string) $user->id);
        }

        return $response;
    }

    // GET /api/platforms/online-ordering/entries/{id}/status — poll enrichment.
    public function entryStatus(Request $request, string $id): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->linkCardStatusResponse($user, $id, fn () => ['entries' => $this->entriesData($user)]);
    }

    // DELETE /api/platforms/online-ordering/entries/{id}
    public function removeEntry(Request $request, string $id): JsonResponse
    {
        $user = $this->currentUser($request);

        // MenuFetchJob runs a real menu scrape inline under sync queue and takes
        // no platform lock of its own — dispatching it INSIDE the lock closure
        // would hold the 10s lock across a scrape that can run up to ~240s,
        // letting the lock expire mid-operation. Dispatch only after the lock
        // releases, and only when a delete actually happened (never on 404/423).
        $removed = false;
        $response = $this->withConnectionLock($user, function () use ($user, $id, &$removed) {
            $target = $this->entryRows($user)->firstWhere('resource_id', $id);
            if (! $target) {
                return $this->error('Ordering link not found.', 404);
            }

            // The id is the consolidated entry's id (the store's primary row). Remove
            // EVERY row for that store so a pickup+delivery pair disappears in one
            // click — not just the primary, which would leave the sibling orphaned.
            $storeKey = $this->storeKey(CardPayload::fromArray($target->payload)->url());
            $rids = $storeKey === null
                ? [$id]
                : $this->entryRows($user)
                    ->filter(fn (IntegrationConnection $row) => $this->storeKey(CardPayload::fromArray($row->payload)->url()) === $storeKey)
                    ->pluck('resource_id')
                    ->all();

            foreach ($rids as $rid) {
                $this->forgetConnection($user, $rid);
            }
            $removed = true;

            return $this->success(['entries' => $this->entriesData($user)]);
        });

        if ($removed) {
            MenuFetchJob::dispatch((string) $user->id);
        }

        return $response;
    }

    // DELETE /api/platforms/online-ordering — remove every entry.
    public function forget(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        // See removeEntry() — MenuFetchJob dispatch stays outside the lock,
        // gated on the response actually clearing (200, not a 423 lock timeout).
        $response = $this->withConnectionLock($user, function () use ($user) {
            $this->forgetAllConnections($user);

            return $this->success(['entries' => []]);
        });

        if ($response->getStatusCode() === 200) {
            MenuFetchJob::dispatch((string) $user->id);
        }

        return $response;
    }

    // ── internals ────────────────────────────────────────────────

    /**
     * Write this endpoint's card onto an ordering row that already exists —
     * either the store being merged into, or the one LinkRouter just seeded.
     *
     * Not writePendingLinkCard(): that upserts on `platform()`, and the family's
     * rows no longer share one. The row is already resolved here, so the write
     * is a plain policy-gated save. assertPlatformAvailable() is kept because a
     * staff takedown of the ordering integration must still refuse the write —
     * it is a per-family switch, so the family key is the right thing to ask
     * about.
     *
     * @param  array<string,mixed>  $payload
     */
    private function mergeEntryCard(User $user, IntegrationConnection $row, array $payload): void
    {
        $this->assertPlatformAvailable($user);
        $this->authorizeForUser($user, 'update', $row);

        $row->fill([
            'payload' => $payload,
            'is_active' => true,
            'last_refreshed_at' => null,
            'last_refresh_status' => 'pending',
            'last_refresh_error' => null,
            'consecutive_failures' => 0,
        ])->save();
    }

    /** The catalog's display name for a surface, or null when it has none. */
    private function brandLabel(string $surfaceKey): ?string
    {
        $name = CompiledCatalog::surface($surfaceKey)['display_name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /** @return Collection<int, IntegrationConnection> */
    private function entryRows(User $user)
    {
        return $this->connectionsFor($user)->values();
    }

    /**
     * The consolidated ordering entries — one entry per store. Rows for the same
     * store (same platform + store path, e.g. a pickup-typed row and a
     * delivery-typed row from the Google Business harvest) collapse into a single
     * entry that carries BOTH a pickup and a delivery URL under `data`, so the UI
     * no longer shows the same store twice.
     *
     * Shape stays compatible with the frontend OnlineOrderingEntry: keeps
     * id/provider/url/name/favicon/logo/source/data; `data` gains optional
     * pickupUrl/deliveryUrl. `id` is the first (newest) row's resource id so the
     * existing remove-by-id flow keeps working.
     *
     * @return list<array<string,mixed>>
     */
    private function entriesData(User $user): array
    {
        $groups = [];
        foreach ($this->entryRows($user) as $row) {
            $card = CardPayload::fromArray($row->payload);
            // Group by store; an unkeyable url (shouldn't happen) gets its own slot.
            $key = $this->storeKey($card->url()) ?? ('row:'.$row->resource_id);
            $groups[$key] ??= [];
            $groups[$key][] = ['rid' => $row->resource_id, 'payload' => $card->toArray()];
        }

        $out = [];
        foreach ($groups as $rows) {
            $out[] = $this->consolidateEntry($rows);
        }

        return $out;
    }

    /**
     * Collapse one store's rows into a single entry. The first row (newest —
     * entryRows is sort_order then created_at, and the harvest writes in order)
     * supplies the card identity; pickup/delivery URLs are gathered from whichever
     * rows carry that mode (typed rows, or a mode parsed from the URL).
     *
     * @param  list<array{rid:string, payload:array<string,mixed>}>  $rows
     * @return array<string,mixed>
     */
    private function consolidateEntry(array $rows): array
    {
        $primary = CardPayload::fromArray($rows[0]['payload']);
        $data = $primary->data();

        $pickupUrl = $data['pickupUrl'] ?? null;
        $deliveryUrl = $data['deliveryUrl'] ?? null;
        foreach ($rows as $row) {
            $card = CardPayload::fromArray($row['payload']);
            $url = $card->url();
            $type = $card->data()['type'] ?? null;
            $mode = ($type === 'pickup' || $type === 'delivery') ? $type : $this->modeOf($url);
            if ($mode === 'pickup') {
                $pickupUrl ??= $url;
            } elseif ($mode === 'delivery') {
                $deliveryUrl ??= $url;
            }
        }

        $data = array_filter([
            ...$data,
            'pickupUrl' => $pickupUrl,
            'deliveryUrl' => $deliveryUrl,
        ], fn ($v) => $v !== null);

        return [
            'id' => $rows[0]['rid'],
            'provider' => $primary->provider() ?? 'custom',
            'url' => $primary->url(),
            'name' => $primary->name(),
            'favicon' => $primary->favicon(),
            'logo' => $primary->logo(),
            'source' => $primary->source() ?? 'manual',
            'data' => $data === [] ? null : $data,
        ];
    }

    /**
     * Fold a newly-added link's URL into a store payload's pickup/delivery slots
     * so one row carries both modes. The URL's own declared mode (Uber Eats
     * diningMode) decides the slot; an untyped link only sets the representative
     * `url` (left as-is when already present).
     *
     * @param  array<string,mixed>  $payload
     * @param  array{url:string, name:?string, description:?string, favicon:?string, logo:?string}  $meta
     * @return array<string,mixed>
     */
    private function mergeStorePayload(array $payload, array $meta, ?string $mode): array
    {
        $data = CardPayload::fromArray($payload)->data();
        if ($mode === 'pickup') {
            $data['pickupUrl'] = $meta['url'];
        } elseif ($mode === 'delivery') {
            $data['deliveryUrl'] = $meta['url'];
        }

        $payload['data'] = $data === [] ? null : $data;

        return $payload;
    }

    /**
     * A store grouping key — "<host-or-platform>|<path>", query + fragment +
     * trailing slash stripped (so Uber Eats ?diningMode / ?ps / ?mod variants of
     * one store collapse). Null for a non-URL input.
     */
    private function storeKey(mixed $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }
        $parts = parse_url(trim($url));
        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }
        $host = strtolower($parts['host']);
        $path = rtrim($parts['path'] ?? '', '/');

        return $host.'|'.$path;
    }

    /**
     * The ordering mode a URL declares, or null. Only Uber Eats encodes it in the
     * URL (?diningMode=PICKUP|DELIVERY); everything else is mode-agnostic here
     * (DoorDash / manual links rely on the harvested data.type instead).
     */
    private function modeOf(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $dining = strtolower((string) ($query['diningMode'] ?? ''));

        return match ($dining) {
            'pickup' => 'pickup',
            'delivery' => 'delivery',
            default => null,
        };
    }
}
