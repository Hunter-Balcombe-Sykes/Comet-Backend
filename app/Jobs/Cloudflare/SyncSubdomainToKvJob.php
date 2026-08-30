<?php

namespace App\Jobs\Cloudflare;

use App\Jobs\Concerns\HasCloudflareRetryPolicy;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\Cloudflare\CloudflareKvService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
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
// `ShouldBeUniqueUntilProcessing` with a 45s window collapses observer storms to a
// single KV write per 45s. "UntilProcessing" (not plain ShouldBeUnique) matters
// because HasCloudflareRetryPolicy's backoff ([10,30,60]) can span ~100s worst-case
// — longer than uniqueFor — so a plain ShouldBeUnique lock would still be held (or
// have expired mid-retry and collided) during a legitimate re-dispatch. Releasing
// the lock the moment a worker picks the job up (rather than after handle()
// completes) is safe here because handle() re-reads all state fresh from the DB —
// there's no dispatch-time snapshot to go stale.
class SyncSubdomainToKvJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
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

    // Fold both retire signals into the unique key so a delete-triggered retire
    // dispatch (capturedHandle set — UserObserver::deleted) or a domain-removal
    // sync (retireCustomDomain set) is never collapsed into a concurrent plain
    // sync (handle-change/restore, which dispatches with neither set) — that
    // collapse would silently drop the retire (WHK-1). This job is
    // ShouldBeUniqueUntilProcessing, not plain ShouldBeUnique (see the class
    // docblock above): the dedup lock only guards the "queued but not yet
    // picked up by a worker" window, releasing the moment a worker starts
    // handle(), not after it completes.
    public function uniqueId(): string
    {
        return $this->userId
            .($this->capturedHandle ? ':retire-handle:'.strtolower(trim($this->capturedHandle)) : '')
            .($this->retireCustomDomain ? ':retire:'.strtolower(trim($this->retireCustomDomain)) : '');
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

        // Routable = active OR unclaimed (pre-account build; publish is a separate
        // knob — an unpublished site 404s at the payload layer, same as today).
        // Otherwise retire: the user is gone (hard/soft-deleted or no handle) OR
        // the account is suspended/disabled/pending-deletion. A suspended user is
        // NOT trashed, so without this gate a moderation takedown left the route —
        // and the edge-cached page — live (EDGE-3). Mirrors the public read path
        // (public_site_payload requires status='active' OR 'unclaimed').
        if (! $pro || $pro->trashed() || ! $pro->handle || ! ($pro->isActive() || $pro->isUnclaimed())) {
            $this->retire($kv, $pro);

            return;
        }

        // An unclaimed account whose build FAILED is not routable either. The
        // clause above reads "unclaimed = routable", which is right for a build
        // that worked and wrong for one that did not: SiteObserver publishes the
        // subdomain the moment the site ROW is created, before the build runs, and
        // GeneratePreAccountSiteJob's failure arms return before their own KV
        // sync — so nothing ever took the route back down.
        //
        // Found live 2026-08-30: three Instagram scrapes failed in one batch and
        // all three served HTTP 200 at their real subdomain — a public page
        // carrying a REAL PERSON'S NAME, no bio, no images, no links, just
        // "Claim and finish site setup". For a product whose whole premise is
        // building someone a site before they ask, that is the worst possible
        // render, and worse than a 404.
        //
        // Scoped to FAILED, not to "not yet ready": a build in flight resolves in
        // seconds and the success path re-syncs at the end, while a failed build
        // is permanent. A later successful rebuild flips build_state to ready and
        // dispatches its own sync, which re-adds the route.
        //
        // Safe for claiming: the claim link is {frontend_url}/claim/{subdomain}
        // (ClaimNotifier), on the app frontend — it never depended on the
        // subdomain resolving.
        if ($pro->isUnclaimed() && $this->latestBuildFailed($pro)) {
            $this->retire($kv, $pro);

            return;
        }

        $current = strtolower(trim((string) $pro->handle));

        // Read the site once — needed for both the moderation gate below and the
        // custom-domain publish. A genuinely missing site row reads as null (no
        // throw — it's a hasOne). A real DB failure here MUST propagate: swallowing
        // it previously false-negated the moderation gate below (`$site &&
        // moderation_state === 'hidden'`), re-publishing a just-hidden site to KV
        // (JOB-101). The job's retry policy ($tries/$backoff/failed()) exists
        // precisely to handle this as a transient error, not a silent no-op.
        $site = $pro->site;

        // Moderation has hidden the site — retire the route so a hide_site
        // takedown (which hides the SITE, not the user) also stops resolving.
        if ($site && ($site->moderation_state ?? 'active') === 'hidden') {
            $this->retire($kv, $pro);

            return;
        }

        // KV TTL defense-in-depth (spec §4): an unclaimed owner's entry expires at
        // the edge in lockstep with the build, so a failed prune can't leave a
        // routable orphan. Claiming re-dispatches this job with status=active,
        // rewriting the entry permanent (null TTL).
        $ttl = null;
        if ($pro->isUnclaimed()) {
            $expiresAt = $pro->preAccountBuild?->expires_at;
            if (! $expiresAt || now()->gte($expiresAt)) {
                $this->retire($kv, $pro); // expired (or buildless) unclaimed — treat as gone

                return;
            }
            // Cloudflare KV enforces a 60s minimum TTL.
            $ttl = max(60, (int) now()->diffInSeconds($expiresAt, false));
        }

        // All accounts are individual. The Astro Worker reads this entry via
        // Service Binding and renders the public profile page.
        $kv->put($current, ['type' => 'individual'], $ttl);

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
     * Delete the routing entries for a gone/taken-down user. Prefers the
     * soft-deleted model's own handle; falls back to the handle captured at
     * dispatch time (the only source available once a row is hard-deleted).
     * Also retires the custom-domain pointer (`domain:<host>`) for a still-
     * resolvable site, so a soft-delete / suspend / moderation-hide takedown
     * closes both the handle and custom-domain routes (EDGE-1). The staff
     * force-delete path can't resolve the site here — the user row is already
     * gone — so StaffUserController::forceDestroy retires the domain explicitly
     * via the $retireCustomDomain param instead. Idempotent — a missing key
     * delete is a no-op at Cloudflare. Aliases are left to expire via their own
     * TTL / the handles:prune-expired-aliases sweep.
     */
    /**
     * Whether this unclaimed account's most recent pre-account build failed.
     *
     * Read straight off the table rather than through a relation: this job runs
     * with lazy-loading prevention armed outside production, and the column set
     * is two values. Table name comes from the MODEL, not a literal — the schema
     * prefix differs between the SQLite test database and Postgres.
     */
    private function latestBuildFailed(User $pro): bool
    {
        $state = DB::table((new PreAccountBuild)->getTable())
            ->where('user_id', $pro->id)
            ->orderByDesc('created_at')
            ->value('build_state');

        return $state === 'failed';
    }

    private function retire(CloudflareKvService $kv, ?User $pro): void
    {
        $handle = strtolower(trim((string) ($pro?->handle ?: $this->capturedHandle)));

        if ($handle !== '') {
            $kv->delete($handle);
        }

        // EDGE-1: also retire the custom-domain pointer on takedown. `domain:<host>`
        // is written by the active branch of handle() and is otherwise ONLY cleared
        // on a voluntary disconnect (the $retireCustomDomain path) — never on a
        // delete/suspend/moderation-hide. Without this the user's own domain keeps
        // routing to their taken-down page indefinitely (a cache purge alone just
        // resets the clock — the surviving KV pointer re-warms the edge on the next
        // request). The handle delete above has already run (retries are idempotent
        // — a missing-key delete is a no-op at Cloudflare), so a real DB failure
        // reading the site here MUST propagate rather than be swallowed: silently
        // skipping this delete leaves a taken-down user's custom domain serving
        // (JOB-102). Let the job's retry policy handle it as a transient error.
        $site = $pro?->site;

        if ($site) {
            $customDomain = strtolower(trim((string) ($site->custom_domain ?? '')));
            if ($customDomain !== '' && ($site->custom_domain_status ?? null) === 'active') {
                $kv->delete("domain:{$customDomain}");
            }
        }
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

            // LIFE-109 (revised): skip a genuinely-future-but-sub-60s TTL rather than
            // flooring it to Cloudflare's 60s minimum. Flooring would extend a
            // reclaimable alias past its real expiry: SubdomainAvailabilityService
            // treats the handle as free the instant expires_at passes (no grace
            // period), so a different user can register it immediately, while the
            // new owner's KV overwrite is only dispatched async from
            // UserObserver::updated(). Any resync landing in that final sub-60s window
            // would floor the OLD owner's entry to outlive expires_at by up to 60s —
            // 301'ing a visitor to a superseded owner's site. Skipping costs nothing
            // in practice: every prior sync already wrote a TTL computed from the
            // same expires_at, so KV already carries a close-to-correct expiry; this
            // only declines a write, never invalidates one.
            if ($ttl !== null && $ttl < 60) {
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
