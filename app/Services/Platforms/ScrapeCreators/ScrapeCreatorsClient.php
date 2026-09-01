<?php

namespace App\Services\Platforms\ScrapeCreators;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

// Item 8 (2026-09-01, owner-signed G1): the fast-vendor lane. ScrapeCreators
// answers profile queries in ~2-4s where an Apify run-sync actor holds a
// worker 15-60s; it is the PRIMARY for the sources G3 assigned it, and Apify
// is the fallback on every miss — so this client's contract is deliberately
// lossy: any failure of any kind returns null and the caller falls through.
// Nothing downstream may ever depend on this lane being up.
//
// Trial-verified quirks this client absorbs (docs/plans/2026-09-01 plan,
// Item 8 adapter notes): a NotFound answer bills a credit and arrives as
// success:true — shape gating is the caller's job, never HTTP status; and
// credits_* ride inside the body — strip them before persisting payloads.
class ScrapeCreatorsClient
{
    private const BASE = 'https://api.scrapecreators.com';

    /** Fallback default for config('partna.limits.scrapecreators.timeout_seconds'). */
    private const TIMEOUT_SECONDS = 20;

    public function enabled(): bool
    {
        return is_string($this->key()) && $this->key() !== '';
    }

    /**
     * GET one vendor endpoint. Returns the decoded JSON body, or null on
     * missing key / transport failure / non-2xx / undecodable body. The
     * caller owns shape validation — a 200 with success:true can still be a
     * NotFound husk.
     *
     * @param  array<string, string|int>  $query
     * @return array<string, mixed>|null
     */
    public function get(string $path, array $query, ?string $userId = null): ?array
    {
        $key = $this->key();
        if (! is_string($key) || $key === '') {
            return null;
        }

        $startedAt = microtime(true);
        try {
            $response = Http::withHeaders(['x-api-key' => $key])
                ->timeout((int) config('partna.limits.scrapecreators.timeout_seconds', self::TIMEOUT_SECONDS))
                ->get(self::BASE.$path, $query);
        } catch (Throwable $e) {
            Log::info('scrapecreators.transport_failed', [
                'path' => $path,
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);

            return null;
        }

        $ms = (int) ((microtime(true) - $startedAt) * 1000);
        if (! $response->successful()) {
            // 400/403/404 all bill a credit upstream — logged as info, not
            // warning: a nonexistent handle is a prospect's typo, not an
            // operator event, and the Apify fallback still answers it.
            Log::info('scrapecreators.non_2xx', [
                'path' => $path, 'user_id' => $userId,
                'status' => $response->status(), 'ms' => $ms,
            ]);

            return null;
        }

        $body = $response->json();
        if (! is_array($body)) {
            Log::info('scrapecreators.undecodable', ['path' => $path, 'user_id' => $userId, 'ms' => $ms]);

            return null;
        }

        Log::info('scrapecreators.fetch', [
            'path' => $path,
            'user_id' => $userId,
            'ms' => $ms,
            'credits_charged' => $body['credits_charged'] ?? null,
        ]);

        return $body;
    }

    private function key(): ?string
    {
        $key = config('services.scrapecreators.key');

        return is_string($key) ? $key : null;
    }
}
