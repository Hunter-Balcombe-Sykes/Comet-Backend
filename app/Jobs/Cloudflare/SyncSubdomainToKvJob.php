<?php

namespace App\Jobs\Cloudflare;

use App\Jobs\Concerns\HasCloudflareRetryPolicy;
use App\Models\Core\User\User;
use App\Services\Cloudflare\CloudflareKvService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

// Reconciles one professional's subdomain routing entries in Cloudflare KV
// against the user's actual state. This is the SINGLE writer to SUBDOMAIN_KV
// (§50 non-negotiable rule #5) — both upserts AND deletes flow through here so
// there is exactly one code path that mutates the routing table.
//
// Active user  → upsert {"type":"individual"} for the current handle, plus
//                {"type":"alias","redirect":"https://<current>.partna.au"} (with
//                expirationTtl) for every historical alias. The Worker reads
//                `entry.redirect` as a full URL — it does NOT reconstruct from a
//                handle, so pre-computing the canonical URL here keeps alias
//                entries valid regardless of future Worker-side URL composition.
// Gone user    → soft-deleted / hard-deleted / handle cleared: delete the KV
//                entry so <handle>.partna.au stops resolving immediately and the
//                handle can be cleanly reclaimed by a new user. The handle is
//                captured at dispatch time ($capturedHandle) because a
//                hard-deleted row is no longer findable; for a soft-delete we
//                fall back to reading it via withTrashed().
//
// `ShouldBeUnique` with a 45s window collapses observer storms to a single KV write per 45s.
class SyncSubdomainToKvJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, HasCloudflareRetryPolicy, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    public int $uniqueFor = 45;

    /**
     * @param  string  $userId  The professional whose KV routing to reconcile.
     * @param  string|null  $capturedHandle  The handle to delete when the user is
     *                                       gone (passed by UserObserver::deleted,
     *                                       since a hard-deleted row can't be looked
     *                                       up). Null for normal active-state syncs.
     */
    public function __construct(public readonly string $userId, public readonly ?string $capturedHandle = null)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return $this->userId;
    }

    public function handle(CloudflareKvService $kv): void
    {
        // withTrashed so a soft-deleted user is still found here — the find()
        // exclusion was the original bug (deleted users left their KV live).
        $pro = User::withTrashed()->find($this->userId);

        // The user is gone (hard-deleted, soft-deleted, or has no handle) — remove
        // the routing entry instead of upserting it.
        if (! $pro || $pro->trashed() || ! $pro->handle) {
            $this->retire($kv, $pro);

            return;
        }

        $current = strtolower(trim((string) $pro->handle));

        // All accounts are individual. The Astro Worker reads this entry via
        // Service Binding and renders the public profile page.
        $kv->put($current, ['type' => 'individual'], null);

        // Pre-compute the canonical URL for alias-redirect entries. Format
        // matches site.compute_user_url() — `https://<handle>.partna.au` —
        // and the trg_recompute_partna_url DB trigger keeps
        // core.users.partna_url in sync so this fallback is rarely needed.
        $canonical = $pro->partna_url ?: "https://{$current}.partna.au";
        $this->writeAliasEntries($kv, $pro->id, $current, $canonical);
    }

    /**
     * Delete the routing entry for a gone user. Prefers the soft-deleted model's
     * own handle; falls back to the handle captured at dispatch time (the only
     * source available once a row is hard-deleted). Idempotent — a missing key
     * delete is a no-op at Cloudflare. Aliases are left to expire via their own
     * TTL / the handles:prune-expired-aliases sweep.
     */
    private function retire(CloudflareKvService $kv, ?User $pro): void
    {
        $handle = strtolower(trim((string) ($pro?->handle ?: $this->capturedHandle)));

        if ($handle === '') {
            return;
        }

        $kv->delete($handle);
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('cloudflare.sync_subdomain_to_kv.failed', [
            'user_id' => $this->userId,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * Write alias KV entries — {type:'alias', redirect:'https://<current>.partna.au'}
     * with a TTL derived from expires_at so Cloudflare auto-evicts when the
     * alias expires. Legacy NULL-expires_at aliases get no TTL (permanent
     * until the next prune sweep).
     *
     * The Worker contract reads `entry.redirect` as a full URL — sending
     * `target: <handle>` (the older shape) silently falls through the
     * Worker's alias branch and routes to fetch(request), producing an
     * infinite self-loop that surfaces to the visitor as a 522.
     */
    private function writeAliasEntries(CloudflareKvService $kv, string $proId, string $current, string $canonical): void
    {
        $aliases = DB::table('core.user_handle_aliases')
            ->where('user_id', $proId)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->get();

        foreach ($aliases as $alias) {
            $handle = strtolower(trim($alias->handle));
            if ($handle === '' || $handle === $current) {
                continue;
            }

            // max(60, ...) so we never pass a sub-minimum TTL to CF KV.
            $ttl = $alias->expires_at
                ? max(60, (int) now()->diffInSeconds(Carbon::parse($alias->expires_at), false))
                : null;

            $kv->put($handle, ['type' => 'alias', 'redirect' => $canonical], $ttl);
        }
    }
}
