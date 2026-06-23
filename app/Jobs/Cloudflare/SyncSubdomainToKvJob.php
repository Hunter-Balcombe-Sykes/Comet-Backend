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
     * @param  string|null  $retireCustomDomain  A custom domain to remove from KV
     *                                           (the user disconnected or changed it).
     *                                           Deleted regardless of user state.
     */
    public function __construct(
        public readonly string $userId,
        public readonly ?string $capturedHandle = null,
        public readonly ?string $retireCustomDomain = null,
    ) {
        // Isolated from user-facing work so a burst of platform-connection writes
        // can't delay notifications or mail delivery.
        $this->onQueue(config('partna.queues.cloudflare', 'cloudflare'));
    }

    // Include the retire-domain in the unique key so a domain-removal sync is not
    // collapsed into a concurrent plain sync (which would drop the deletion).
    public function uniqueId(): string
    {
        return $this->userId.($this->retireCustomDomain ? ':retire:'.strtolower(trim($this->retireCustomDomain)) : '');
    }

    public function handle(CloudflareKvService $kv): void
    {
        // Retire a removed/changed custom domain first — independent of user state
        // so a disconnected domain stops resolving even as the rest reconciles.
        if ($this->retireCustomDomain) {
            $retire = strtolower(trim($this->retireCustomDomain));
            if ($retire !== '') {
                $kv->delete("domain:{$retire}");
            }
        }

        // withTrashed so a soft-deleted user is still found here — the find()
        // exclusion was the original bug (deleted users left their KV live).
        $pro = User::withTrashed()->find($this->userId);

        // Retire the routing entry whenever the profile is NOT publicly servable:
        // the user is gone (hard/soft-deleted or no handle) OR the account is
        // suspended/disabled/pending-deletion (status !== 'active'). A suspended
        // user is NOT trashed, so without the isActive() gate a moderation
        // takedown left the route — and the edge-cached page — live (EDGE-3).
        // Mirrors the public read path (public_site_payload requires status='active').
        if (! $pro || $pro->trashed() || ! $pro->handle || ! $pro->isActive()) {
            $this->retire($kv, $pro);

            return;
        }

        $current = strtolower(trim((string) $pro->handle));

        // Read the site once — needed for both the moderation gate below and the
        // custom-domain publish. Guarded so a missing/corrupt site row can never
        // black-hole the core subdomain sync.
        $site = null;
        try {
            $site = $pro->site;
        } catch (Throwable $e) {
            report($e);
        }

        // Moderation has hidden the site — retire the route so a hide_site
        // takedown (which hides the SITE, not the user) also stops resolving.
        if ($site && ($site->moderation_state ?? 'active') === 'hidden') {
            $this->retire($kv, $pro);

            return;
        }

        // All accounts are individual. The Astro Worker reads this entry via
        // Service Binding and renders the public profile page.
        $kv->put($current, ['type' => 'individual'], null);

        // Publish an ACTIVE custom domain → handle route (Cloudflare for SaaS). The
        // router Worker reads `domain:<host>` and forwards to partna-pages with the
        // handle injected. Only 'active' domains are written; pending/errored ones
        // stay dark until verified. The KV write stays unguarded so a real write
        // failure still retries the job.
        if ($site) {
            $customDomain = strtolower(trim((string) ($site->custom_domain ?? '')));
            if ($customDomain !== '' && ($site->custom_domain_status ?? null) === 'active') {
                $kv->put("domain:{$customDomain}", ['type' => 'individual', 'handle' => $current], null);
            }
        }

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
     * All active aliases are batched into a single bulkPut call (SCALE-6) to
     * avoid N individual HTTP requests for users with many historical handles.
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

        $entries = [];

        foreach ($aliases as $alias) {
            $handle = strtolower(trim($alias->handle));
            if ($handle === '' || $handle === $current) {
                continue;
            }

            // Compute the raw signed TTL (no max() clamp).
            $ttl = $alias->expires_at
                ? (int) now()->diffInSeconds(Carbon::parse($alias->expires_at), false)
                : null;

            // P3-31: skip already-expired aliases — Cloudflare KV enforces a 60s
            // minimum TTL, so passing a ≤0 TTL would resurrect an expired alias at
            // the edge for up to 60s past its DB expiry. The DB query above already
            // excludes expires_at < now(), but race conditions between query time and
            // this point mean we must guard here too. Aligns with the resolver
            // ->active() scope which also filters expires_at > now().
            if ($ttl !== null && $ttl <= 0) {
                continue;
            }

            $entries[] = [
                'key' => $handle,
                'value' => ['type' => 'alias', 'redirect' => $canonical],
                'expiration_ttl' => $ttl,
            ];
        }

        // SCALE-6: one bulk PUT instead of N individual PUTs.
        $kv->bulkPut($entries);
    }
}
