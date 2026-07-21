<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Jobs\Platforms\EnrichLinkCardJob;
use App\Jobs\Platforms\MenuFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\LinkCardScraper;
use App\Services\Platforms\Payloads\CardPayload;
use App\Services\Platforms\Registry\Platform;
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
        return Platform::OnlineOrdering->value;
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

        return $this->withConnectionLock($user, function () use ($user, $meta, $mode, $storeKey, $url) {
            // Merge-on-add: a link to a store the user already has (same platform +
            // store path) folds into that existing row — one store = one entry
            // carrying both a pickup and a delivery URL — instead of a duplicate.
            $existing = $storeKey === null ? null : $this->entryRows($user)
                ->first(fn (IntegrationConnection $row) => $this->storeKey(CardPayload::fromArray($row->payload)->url()) === $storeKey);

            if (! $existing && $this->entryRows($user)->count() >= self::MAX_ENTRIES) {
                return $this->error('You can add up to '.self::MAX_ENTRIES.' ordering links.', 422);
            }

            if ($existing) {
                $rid = $existing->resource_id;
                $this->writePendingLinkCard($user, $this->mergeStorePayload(CardPayload::fromArray($existing->payload)->toArray(), $meta, $mode), $rid);
            } else {
                $rid = $this->entryResourceId($meta['url']);
                $this->writePendingLinkCard($user, $this->mergeStorePayload([
                    'id' => $rid,
                    'provider' => 'custom',
                    'source' => 'manual',
                    ...$meta,
                ], $meta, $mode), $rid);
            }

            EnrichLinkCardJob::dispatch((string) $user->id, $this->platform(), $rid, $url)->afterCommit();
            // Ordering links drive the shared menu — (re)derive it from them.
            MenuFetchJob::dispatch((string) $user->id);

            return $this->success([
                'status' => 'pending',
                'entries' => $this->entriesData($user),
                'statusUrl' => url("/api/platforms/online-ordering/entries/{$rid}/status"),
            ], 202);
        });
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
