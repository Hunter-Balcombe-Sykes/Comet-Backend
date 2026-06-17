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
        $rid = $this->entryResourceId($meta['url']);

        return $this->withConnectionLock($user, function () use ($user, $meta, $rid) {
            $existing = $this->entryRows($user)->firstWhere('resource_id', $rid);
            if (! $existing && $this->entryRows($user)->count() >= self::MAX_ENTRIES) {
                return $this->error('You can add up to '.self::MAX_ENTRIES.' ordering links.', 422);
            }

            $this->writeConnection($user, [
                'id' => $rid,
                'provider' => 'custom',
                'source' => 'manual',
                ...$meta,
            ], $rid);

            // Ordering links drive the shared menu — (re)derive it from them.
            MenuFetchJob::dispatch((string) $user->id);

            return $this->success(['entries' => $this->entriesData($user)]);
        });
    }

    // DELETE /api/platforms/online-ordering/entries/{id}
    public function removeEntry(Request $request, string $id): JsonResponse
    {
        $user = $this->currentUser($request);
        if (! $this->entryRows($user)->firstWhere('resource_id', $id)) {
            return $this->error('Ordering link not found.', 404);
        }
        $this->forgetConnection($user, $id);
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

    /** @return list<array<string,mixed>> */
    private function entriesData(User $user): array
    {
        return $this->entryRows($user)->map(function (IntegrationConnection $row): array {
            $p = is_array($row->payload) ? $row->payload : [];

            return [
                'id' => $row->resource_id,
                'provider' => $p['provider'] ?? 'custom',
                'url' => $p['url'] ?? null,
                'name' => $p['name'] ?? null,
                'favicon' => $p['favicon'] ?? null,
                'logo' => $p['logo'] ?? null,
                'source' => $p['source'] ?? 'manual',
                'data' => $p['data'] ?? null,
            ];
        })->values()->all();
    }
}
