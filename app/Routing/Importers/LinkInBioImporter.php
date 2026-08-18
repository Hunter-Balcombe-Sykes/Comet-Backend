<?php

namespace App\Routing\Importers;

use App\Jobs\Platforms\CommerceProbeJob;
use App\Models\Core\User\User;
use App\Routing\LinkRoutingService;
use App\Routing\RoutingContext;
use App\Routing\SecretParams;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Notifications\FindingsNotifier;
use App\Services\Platforms\CustomLinkSeeder;
use App\Services\Platforms\WebsiteLinkHarvester;
use Illuminate\Support\Facades\DB;

/**
 * Link-in-bio unroll (Linktree, Beacons, Stan…), on the new router.
 *
 * Nearly the same shape as WebsiteImporter, with one rule that is NOT
 * incidental: a bio-link page is itself a platform, and its own chrome —
 * pricing, blog, help centre, signup — sits in the same anchor soup as the
 * two or three links the account owner actually put there. Measured on a real
 * page (2026-07-20): 58 anchors, 55 of them Linktree's own, all on
 * linktr.ee itself. Skipping same-host links is what stops an unroll turning
 * a user's page into a directory of someone else's marketing.
 *
 * That rule is carried forward verbatim from LinkInBioScanJob, which this
 * replaces once its findings-merge path is migrated too.
 *
 * ONE run, N pages. An Instagram bio harvest hands over every URL it found in
 * one profile (bio text, `external_url`, the link sticker) — that is a single
 * acquisition of one account, and recording it as N separate runs would both
 * burn N of the user's 3 daily slots and lie about what happened. The batch
 * is therefore the unit: one `routing.import_runs` row, one shared dedupe
 * table, one shared link budget.
 */
class LinkInBioImporter
{
    /**
     * Hard cap on links considered from ONE RUN — not per page. A batch is one
     * acquisition, so it gets one budget; a single-URL import is a batch of
     * one and sees the identical cap it always had.
     */
    private const MAX_LINKS = 100;

    /** Hard cap on pages fetched in one run. */
    private const MAX_PAGES = 20;

    /** Run kinds this importer may record. Mirrors routing.import_runs.kind. */
    private const KINDS = ['link_in_bio', 'bio_harvest'];

    /** Probe budget per RUN — parity with the legacy RouteContext::DEFAULT_MAX_PROBES. */
    private const MAX_PROBES = 6;

    public function __construct(
        private readonly SafeUrlFetcher $fetcher,
        private readonly WebsiteLinkHarvester $harvester,
        private readonly LinkRoutingService $routing,
        private readonly CustomLinkSeeder $seeder,
    ) {}

    /**
     * @param  string|list<string>  $bioPageUrls  one page, or the list a bio harvest produced
     * @param  string  $kind  'link_in_bio' (a page the user named) | 'bio_harvest' (URLs lifted off a profile)
     * @return array{outcome: string, observations: int, connected: int, suggested: int, noted: int, skipped_chrome: int, pages: int, pages_unavailable: int}
     */
    public function import(User $user, string|array $bioPageUrls, string $kind = 'link_in_bio'): array
    {
        if (! in_array($kind, self::KINDS, true)) {
            $kind = 'link_in_bio';
        }

        $pages = $this->normalisePages($bioPageUrls);
        $empty = ['observations' => 0, 'connected' => 0, 'suggested' => 0, 'noted' => 0, 'probed' => 0, 'skipped_chrome' => 0, 'pages' => 0, 'pages_unavailable' => 0, 'bio_url_seeded' => false];

        if ($pages === []) {
            return ['outcome' => 'unavailable'] + $empty;
        }

        // source_url records the first page: the column is one URL, and the
        // full list lives in the run's detail rather than being truncated into
        // a column that cannot hold it.
        $runId = ImportRun::start((string) $user->id, $kind, $pages[0]);
        if ($runId === null) {
            return ['outcome' => 'cooldown'] + $empty;
        }

        $context = RoutingContext::forUser($user, $kind, $runId);
        $tally = ['connected' => 0, 'suggested' => 0, 'noted' => 0, 'probed' => 0, 'skipped_chrome' => 0];
        $seen = [];
        $unavailable = 0;

        foreach ($pages as $pageUrl) {
            $response = $this->fetcher->tryFetch($pageUrl);

            if ($response === null || $response['status'] !== 200 || $response['body'] === '') {
                $unavailable++;

                continue;
            }

            $this->unroll($response['finalUrl'], $response['body'], $context, $tally, $seen);
        }

        $fetched = count($pages) - $unavailable;
        $observations = count($seen);

        // Zero-yield floor (N2): a MATCHED bio host that unrolls to nothing is
        // a silent total loss — the detector claimed the URL, so no other path
        // will ever write it. linkin.bio (an Ember SPA, zero anchors in the
        // delivered shell) is the live case. Keyed on nothing-routable, so an
        // all-own-chrome page floors identically.
        $bioUrlSeeded = false;
        if ($fetched > 0 && $observations === 0) {
            $this->seeder->seedCustom($user, $pages[0]);
            $bioUrlSeeded = true;
        }

        // Every page down is the same failure the single-page path always
        // reported. Some down is 'partial' — a caller must be able to tell
        // "found nothing" apart from "could not look".
        $outcome = match (true) {
            $fetched === 0 => 'unavailable',
            $unavailable > 0 => 'partial',
            default => 'ok',
        };

        ImportRun::finish(
            $runId,
            $outcome,
            observations: $observations,
            intents: $tally['connected'] + $tally['suggested'],
            errorClass: $fetched === 0 ? 'fetch_failed' : null,
            // #PRIV-5: minimiseUrl(), not redactUrl() — detail->pages is
            // Scope B (routing.import_runs.detail), a non-secret PII
            // carrier. No uniqueness constraint rides on this column.
            detail: $tally + ['pages' => array_map(SecretParams::minimiseUrl(...), $pages), 'pages_unavailable' => $unavailable, 'bio_url_seeded' => $bioUrlSeeded],
        );

        // Conflict parity with the legacy job: the findings themselves are
        // folded into GET /platforms/instagram/synced at read time
        // (SyncFindingsBridge, B4) — but the legacy path also TOLD the user,
        // and a fold nobody opens is a finding nobody sees. Same dedupe-key
        // shape as LinkInBioScanJob's, so re-runs do not stack.
        $conflicts = DB::table('routing.source_intents')
            ->where('import_run_id', $runId)
            ->where('state', 'blocked')
            ->where('block_reason', 'conflict')
            ->count();

        if ($conflicts > 0) {
            app(FindingsNotifier::class)->notify(
                (string) $user->id,
                'link-in-bio-findings:'.$user->id.':'.sha1($pages[0]),
                'We found more in your bio link',
                'Your link-in-bio page mentions an integration that clashes with one you have connected — review it in Integrations.',
            );
        }

        return ['outcome' => $outcome, 'observations' => $observations, 'pages' => $fetched, 'pages_unavailable' => $unavailable, 'bio_url_seeded' => $bioUrlSeeded] + $tally;
    }

    /**
     * @param  array{connected:int, suggested:int, noted:int, probed:int, skipped_chrome:int}  $tally
     * @param  array<string, true>  $seen
     */
    private function unroll(string $baseUrl, string $body, RoutingContext $context, array &$tally, array &$seen): void
    {
        $ownHost = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));

        foreach ($this->harvester->allOutboundLinks($body, $baseUrl) as $url) {
            if (count($seen) >= self::MAX_LINKS) {
                return;
            }

            // The chrome rule. Same host as the bio page itself = the
            // platform's own navigation, not the user's link.
            if ($ownHost !== '' && strtolower((string) parse_url($url, PHP_URL_HOST)) === $ownHost) {
                $tally['skipped_chrome']++;

                continue;
            }

            $fingerprint = strtolower(trim($url));
            if (isset($seen[$fingerprint])) {
                continue;
            }
            $seen[$fingerprint] = true;

            $result = $this->routing->route($url, $context);

            match ($result['verdict']) {
                'place' => $tally['connected']++,
                'choose', 'hold' => $tally['suggested']++,
                default => $this->handleUnrouted($url, $result, $context, $tally),
            };
        }
    }

    /**
     * A link the router did not connect still belongs to the user. Unknown
     * domains get the legacy commerce probe (the new pipeline's probe set is
     * still 1 of 5 — P8 blocker 1), which seeds a product, a store, or its
     * own custom-link fallback; everything else is carded here, because Note
     * cards are caller-owned on this pipeline (RoutingController does the
     * same). Past the probe budget, unknowns are carded directly — a starved
     * link must land somewhere visible, never vanish.
     *
     * @param  array<string, mixed>  $result
     * @param  array{connected:int, suggested:int, noted:int, probed:int, skipped_chrome:int}  $tally
     */
    private function handleUnrouted(string $url, array $result, RoutingContext $context, array &$tally): void
    {
        if ($context->user === null) {
            $tally['noted']++;

            return;
        }

        $unknown = $result['verdict'] === 'note'
            && in_array($result['reason'] ?? null, ['unknown-domain', 'no-rule-matched'], true);

        if ($unknown && $tally['probed'] < self::MAX_PROBES) {
            CommerceProbeJob::dispatch((string) $context->user->id, $url);
            $tally['probed']++;

            return;
        }

        $tally['noted']++;

        if ($result['verdict'] === 'note') {
            $this->seeder->seedCustom($context->user, $url);
        }
        // 'reject' is carded nowhere, deliberately: unroutable by the
        // canonicaliser (own-infra, shortener, malformed) — legacy skipped
        // these too.
    }

    /**
     * Defensive on purpose: the public signature promises list<string>, but a
     * bio harvest builds its list from scraped text, and one null in it must
     * not become an empty fetch.
     *
     * @param  string|array<array-key, mixed>  $urls
     * @return list<string>
     */
    private function normalisePages(string|array $urls): array
    {
        $pages = [];

        foreach ((array) $urls as $url) {
            if (! is_string($url)) {
                continue;
            }

            $url = trim($url);
            if ($url === '' || isset($pages[strtolower($url)])) {
                continue;
            }

            $pages[strtolower($url)] = $url;
        }

        return array_slice(array_values($pages), 0, self::MAX_PAGES);
    }
}
