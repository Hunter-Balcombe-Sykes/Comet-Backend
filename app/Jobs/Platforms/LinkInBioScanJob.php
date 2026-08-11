<?php

namespace App\Jobs\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Notifications\FindingsNotifier;
use App\Services\Platforms\CustomLinkSeeder;
use App\Services\Platforms\LinkRouter;
use App\Services\Platforms\Registry\Platform;
use App\Services\Platforms\RouteContext;
use App\Services\Platforms\WebsiteLinkHarvester;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

// Unrolls a curated link-in-bio page (Linktree/Milkshake/Beacons/Stan Store)
// found in an Instagram bio: one plain fetch, then every outbound link goes
// through LinkRouter — the SAME gateway a direct bio link and a manual paste use,
// so a link found one hop in gets identical classification, gating and fallback
// (2026-07-25 link classification consolidation). Nothing about the bio-link URL
// itself is persisted; this is a one-time inline scan.
//
// Dispatched off InstagramAutoSync's main loop rather than run inline there: a
// slow or JS-heavy link-in-bio fetch could otherwise blow InstagramConnectJob's
// own timeout and lose already-completed work in the same run (mirrors why PDF
// menu OCR is its own job too).
class LinkInBioScanJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public array $backoff = [30];

    public int $timeout = 60;

    // Matches EnrichLinkCardJob (same directory, same 60s timeout): 300s comfortably
    // exceeds the run itself, leaving queue-wait budget. No default means UniqueLock
    // falls back to `?? 0` and RedisLock treats 0 as "no expiry" (plain SETNX) — a
    // worker killed mid-job (OOM, deploy, timeout) would strand that lock forever.
    public int $uniqueFor = 300;

    // $autoConnectBooking rides in from InstagramAutoSync so an aggregator page
    // one hop in behaves like the flow that found it. Deliberately NOT part of
    // uniqueId() below: two scans of the same page for the same user are the
    // same job whatever their origin, and keying on it would let a staff build
    // and a dashboard connect run concurrently against one bio page.
    public bool $autoConnectBooking = false;

    public function __construct(
        public readonly string $userId,
        public readonly string $bioPageUrl,
        bool $autoConnectBooking = false,
    ) {
        $this->autoConnectBooking = $autoConnectBooking;
        $this->onQueue(config('partna.queues.scraping', 'scraping'));
    }

    public function uniqueId(): string
    {
        return $this->userId.':'.sha1($this->bioPageUrl);
    }

    public function handle(
        SafeUrlFetcher $fetcher,
        WebsiteLinkHarvester $harvester,
        LinkRouter $router,
        CustomLinkSeeder $seeder,
    ): void {
        $user = User::find($this->userId);
        if ($user === null) {
            return;
        }

        $response = $fetcher->tryFetch($this->bioPageUrl);
        $html = is_array($response) && $response['status'] === 200 ? (string) $response['body'] : '';
        if ($html === '') {
            return;
        }
        $baseUrl = $response['finalUrl'] ?? $this->bioPageUrl;
        $ownHost = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));

        // ONE context for the whole page — it carries the commerce probe budget
        // (RouteContext::DEFAULT_MAX_PROBES, formerly this class's own
        // MAX_COMMERCE_PROBES counter, signup-v2 C4) and the
        // first-link-per-platform dedupe. A fresh context per link would give
        // every one of a Linktree page's outbound links its own probe budget,
        // turning one page into an unbounded fan-out on the scraping queue.
        //
        // Inherited from the flow that dispatched this scan — see the constructor.
        $ctx = new RouteContext(autoConnectBooking: $this->autoConnectBooking);
        $findings = [];
        $seen = 0;
        $ownHostSkipped = 0;
        $outcomes = [];

        foreach ($harvester->allOutboundLinks($html, $baseUrl) as $url) {
            $seen++;

            // A curated bio-link page (Linktree et al) is itself a platform with
            // its own site-wide chrome — pricing, blog, help centre — mixed into
            // the same anchor soup as the 2-3 links the account owner actually
            // put there. Confirmed live 2026-07-20: of 58 anchors on one real
            // page, 55 were Linktree's own chrome, every one on linktr.ee itself.
            // Without this, the "nothing vanishes" fallback below would seed
            // every one of them as a custom link on the connecting user's site.
            if ($ownHost !== '' && strtolower((string) parse_url($url, PHP_URL_HOST)) === $ownHost) {
                $ownHostSkipped++;

                continue;
            }

            try {
                // route() + seedCustom() rather than CustomLinkSeeder::seed():
                // identical composition, but it hands back the RouteResult so the
                // findings can be merged into the synced modal below. Calling
                // seed() would swallow them.
                $result = $router->route($user, $url, $ctx);
                $outcomes[$result->outcome] = ($outcomes[$result->outcome] ?? 0) + 1;

                foreach ($result->findings as $finding) {
                    $findings[] = $finding;
                }

                // Gate-denied, seeder failure, tombstoned, or probe budget spent —
                // becomes a custom link. There is no "unmatched suggestions"
                // surface in this async context, so seeding directly is what stops
                // the link vanishing. seedCustom(), never seed(): seed() re-enters
                // the router (Issue H / B1).
                // 'skipped' rides with 'custom' deliberately. It means this
                // link's platform already won its slot this run — a rule about
                // CONNECTIONS (you have one Fresha account), not about links.
                // Dropping it wrote nothing at all: an influencer's second LTK
                // link, or a "watch this video" next to a channel link, simply
                // disappeared from the page with no record anywhere. That is the
                // "nothing vanishes" promise broken by the one outcome this
                // branch forgot. The reentrancy guard returns 'skipped' too, but
                // cannot fire here: allOutboundLinks() is deduped and this loop
                // is sequential, so no URL is ever mid-route when it comes round.
                if ($result->outcome === 'custom' || $result->outcome === 'skipped') {
                    $seeder->seedCustom($user, $url);
                }
            } catch (Throwable $e) {
                // Per-link fault isolation — one bad link never abandons the rest
                // of the page (mirrors InstagramAutoSync::seed()'s own loop).
                $outcomes['error'] = ($outcomes['error'] ?? 0) + 1;
                report($e);
            }
        }

        // A starved link leaves no trace of its own: it becomes a custom link
        // exactly like an examined-and-missed one, so a page whose budget ran
        // out reads as a page that was fully scanned. probes_denied > 0 is the
        // tell, and it is the only place that distinction survives the loop.
        Log::info('platforms.link_in_bio_scan.completed', [
            'user_id' => $this->userId,
            'bio_page_url' => $this->bioPageUrl,
            'links_seen' => $seen,
            'own_host_skipped' => $ownHostSkipped,
            'outcomes' => $outcomes,
            ...$ctx->summary(),
        ]);

        $this->mergeFindingsBack($user, $findings);
    }

    /**
     * Fold this scan's findings into the Instagram connection payload's
     * syncFindings — the SAME list GET /platforms/instagram/synced serves — so a
     * conflict found one hop into the bio page gets the identical Swap surface as
     * one found directly in the bio, instead of vanishing when this job returns.
     * Deduped by platform (the direct bio scan ran first and its finding for a
     * platform stands). New findings also raise a notification: this job races the
     * connect modal's lifetime, so by the time it lands the user has usually
     * navigated away.
     *
     * Phase 8 of the consolidation plan says this method STAYS. It was deleted in
     * the first cut on the reasoning that "findings are now real
     * IntegrationConnection rows" — but a CONFLICT finding writes no row by
     * definition, so that deletion silently threw away every conflict discovered
     * inside a link-in-bio page, along with its notification.
     *
     * @param  list<array<string,mixed>>  $findings
     */
    private function mergeFindingsBack(User $user, array $findings): void
    {
        // PRIV-2: queued, so this lands seconds AFTER InstagramSourceGenerator
        // stripped bioLinks/syncFindings/unmatched from the provisional payload —
        // writing here undid exactly one third of that strip (only syncFindings, as
        // the merge spreads the already-stripped payload). Skip the write AND its
        // bell: a pre-claim user has no dashboard to read findings in, and the
        // scan's real output — the connections and custom links seeded above — is
        // already persisted. Unlike GoogleBusinessFetch's PRIV-1 strip this does not
        // self-heal on claim (the scan runs once), matching what the generator's
        // strip already does to the direct bio-link findings.
        if ($user->isUnclaimed()) {
            return;
        }

        if ($findings === []) {
            return;
        }

        $ig = IntegrationConnection::query()
            ->where('user_id', $this->userId)
            ->where('platform', Platform::Instagram->value)
            ->first();
        if ($ig === null) {
            return;
        }

        // `?? []` not is_array(): payload is annotated non-nullable (NOT NULL in
        // Postgres, default '{}') so is_array() reads as always-true to PHPStan,
        // but the SQLite test mirror IS nullable — see the @property note on
        // IntegrationConnection::$payload.
        $payload = $ig->payload ?? [];
        $existing = array_values(array_filter((array) ($payload['syncFindings'] ?? []), 'is_array'));
        $seenPlatforms = array_flip(array_filter(array_map(
            static fn (array $f) => $f['platform'] ?? null,
            $existing,
        ), 'is_string'));

        $fresh = [];
        foreach ($findings as $finding) {
            $platform = $finding['platform'] ?? null;
            if (is_string($platform) && ! isset($seenPlatforms[$platform])) {
                $seenPlatforms[$platform] = true;
                $fresh[] = $finding;
            }
        }
        if ($fresh === []) {
            return;
        }

        // Quiet write, matching InstagramController::applySync's findings-only
        // update: syncFindings are internal (never in the public resource), so
        // no cache purge or observer churn is warranted.
        $ig->forceFill(['payload' => [...$payload, 'syncFindings' => [...$existing, ...$fresh]]])->saveQuietly();

        // Bell only for conflicts — a decision the user doesn't know they have.
        // Seeded findings are already visible as real connections in
        // Integrations; pinging for those would just be noise.
        $hasConflict = array_filter($fresh, static fn (array $f) => ($f['outcome'] ?? null) === 'conflict') !== [];
        if ($hasConflict) {
            app(FindingsNotifier::class)->notify(
                $this->userId,
                'link-in-bio-findings:'.$this->userId.':'.sha1($this->bioPageUrl),
                'We found more in your bio link',
                'Your link-in-bio page mentions an integration that clashes with one you have connected — review it in Integrations.',
            );
        }
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('platforms.link_in_bio_scan.failed', [
            'user_id' => $this->userId,
            'bio_page_url' => $this->bioPageUrl,
            'error' => $e->getMessage(),
        ]);
    }
}
