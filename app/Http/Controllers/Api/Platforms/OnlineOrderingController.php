<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Jobs\Platforms\MenuFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\LinkCardScraper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

// Online ordering — a multi-entry category. The user pastes one or more
// ordering links (Uber Eats, DoorDash, a direct order page); each is fetched
// once and snapshotted into a branded card (one row per entry, resource_id
// 'order-<hash>'). We don't integrate any ordering PLATFORM yet, so every
// manual entry is provider:'custom'. Google Business auto-seeds entries here
// too (source:'google-business') carrying the metadata Google gives us
// (delivery/pickup type, fees, est. time, platform name) under `data`.
//
// Dashboard-only: these rows never reach the public sitepage (excluded by
// PublicIntegrationController + the empty public allowlist entry).
class OnlineOrderingController extends ApiController
{
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    private const MAX_ENTRIES = 10;

    public function __construct(private readonly LinkCardScraper $scraper) {}

    protected function platform(): string
    {
        return 'online-ordering';
    }

    // GET /api/platforms/online-ordering/entries
    public function entries(Request $request): JsonResponse
    {
        return $this->success(['entries' => $this->entriesData($this->currentUser($request))]);
    }

    // POST /api/platforms/online-ordering/entries — attach an ordering link.
    public function addEntry(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $validated = $request->validate(['url' => ['required', 'string', 'max:1000']]);

        // Fetch + parse outside the lock (slow external HTTP).
        $url = $this->scraper->normalizeUrl($validated['url']);
        if (! $url) {
            return $this->error('Enter a valid link (https://...).', 422);
        }
        // Never rejects on an unfetchable page (bot-blocked platforms like Uber
        // Eats) — it falls back to a minimal card so any ordering link attaches.
        $meta = $this->scraper->snapshotOrMinimal($url);

        // The mode the URL itself declares (Uber Eats ?diningMode=PICKUP|DELIVERY),
        // used to slot a merge-on-add into the right pickup/delivery URL.
        $mode = $this->modeOf($meta['url']);
        $storeKey = $this->storeKey($meta['url']);

        return $this->withConnectionLock($user, function () use ($user, $meta, $mode, $storeKey) {
            // Merge-on-add: a link to a store the user already has (same platform +
            // store path) folds into that existing row — one store = one entry
            // carrying both a pickup and a delivery URL — instead of a duplicate.
            $existing = $storeKey === null ? null : $this->entryRows($user)
                ->first(fn (IntegrationConnection $row) => $this->storeKey(data_get($row->payload, 'url')) === $storeKey);

            if (! $existing && $this->entryRows($user)->count() >= self::MAX_ENTRIES) {
                return $this->error('You can add up to '.self::MAX_ENTRIES.' ordering links.', 422);
            }

            if ($existing) {
                $this->writeConnection($user, $this->mergeStorePayload($existing->payload ?? [], $meta, $mode), $existing->resource_id);
            } else {
                $rid = $this->entryResourceId($meta['url']);
                $this->writeConnection($user, $this->mergeStorePayload([
                    'id' => $rid,
                    'provider' => 'custom',
                    'source' => 'manual',
                    ...$meta,
                ], $meta, $mode), $rid);
            }

            // Ordering links drive the shared menu — (re)derive it from them.
            MenuFetchJob::dispatch((string) $user->id);

            return $this->success(['entries' => $this->entriesData($user)]);
        });
    }

    // DELETE /api/platforms/online-ordering/entries/{id}
    public function removeEntry(Request $request, string $id): JsonResponse
    {
        $user = $this->currentUser($request);
        $target = $this->entryRows($user)->firstWhere('resource_id', $id);
        if (! $target) {
            return $this->error('Ordering link not found.', 404);
        }

        // The id is the consolidated entry's id (the store's primary row). Remove
        // EVERY row for that store so a pickup+delivery pair disappears in one
        // click — not just the primary, which would leave the sibling orphaned.
        $storeKey = $this->storeKey(data_get($target->payload, 'url'));
        $rids = $storeKey === null
            ? [$id]
            : $this->entryRows($user)
                ->filter(fn (IntegrationConnection $row) => $this->storeKey(data_get($row->payload, 'url')) === $storeKey)
                ->pluck('resource_id')
                ->all();

        foreach ($rids as $rid) {
            $this->forgetConnection($user, $rid);
        }
        MenuFetchJob::dispatch((string) $user->id);

        return $this->success(['entries' => $this->entriesData($user)]);
    }

    // DELETE /api/platforms/online-ordering — remove every entry.
    public function forget(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $this->forgetAllConnections($user);
        MenuFetchJob::dispatch((string) $user->id);

        return $this->success(['entries' => []]);
    }

    // ── internals ────────────────────────────────────────────────

    private function entryResourceId(string $url): string
    {
        return 'order-'.substr(sha1(strtolower($url)), 0, 16);
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
            $p = is_array($row->payload) ? $row->payload : [];
            // Group by store; an unkeyable url (shouldn't happen) gets its own slot.
            $key = $this->storeKey($p['url'] ?? null) ?? ('row:'.$row->resource_id);
            $groups[$key] ??= [];
            $groups[$key][] = ['rid' => $row->resource_id, 'payload' => $p];
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
        $primary = $rows[0]['payload'];
        $data = is_array($primary['data'] ?? null) ? $primary['data'] : [];

        $pickupUrl = $data['pickupUrl'] ?? null;
        $deliveryUrl = $data['deliveryUrl'] ?? null;
        foreach ($rows as $row) {
            $p = $row['payload'];
            $url = $p['url'] ?? null;
            $mode = (data_get($p, 'data.type') === 'pickup' || data_get($p, 'data.type') === 'delivery')
                ? data_get($p, 'data.type')
                : $this->modeOf($url);
            if ($mode === 'pickup') {
                $pickupUrl ??= $url;
            } elseif ($mode === 'delivery') {
                $deliveryUrl ??= $url;
            }
        }

        // Fold the resolved mode URLs back into data (drop nulls so an untyped
        // single-link store keeps a clean `data`).
        $data = array_filter([
            ...$data,
            'pickupUrl' => $pickupUrl,
            'deliveryUrl' => $deliveryUrl,
        ], fn ($v) => $v !== null);

        return [
            'id' => $rows[0]['rid'],
            'provider' => $primary['provider'] ?? 'custom',
            'url' => $primary['url'] ?? null,
            'name' => $primary['name'] ?? null,
            'favicon' => $primary['favicon'] ?? null,
            'logo' => $primary['logo'] ?? null,
            'source' => $primary['source'] ?? 'manual',
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
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
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
