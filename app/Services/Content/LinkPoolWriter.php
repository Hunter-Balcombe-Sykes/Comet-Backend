<?php

namespace App\Services\Content;

use App\Ingest\Projection\ProjectionWriter;
use App\Jobs\Content\EnrichPoolLinkJob;
use App\Models\Core\Site\SectionItem;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Site\Documents\SiteCacheLanes;
use App\Site\Pools\PoolSectionProvisioner;
use Illuminate\Support\Facades\DB;

/**
 * Convergence Phase 6: the live write lane for the `custom_links` pool.
 *
 * Phase 3 carried the 23 existing `partna.custom_link` connections onto
 * `content.*` and named this seam explicitly — "the pool is a SNAPSHOT until
 * Phase 6 … the (retired) custom-link backfiller's linkProjection() was the seam"
 * (parent spec §22). This is the other half: every NEW link is written straight
 * onto the pool, so nothing lands on the retired surface again.
 *
 * It is also where three other paths now put a link they cannot type. Under the
 * owner ruling of 2026-08-16, a URL whose brand has no working home — an ordering
 * link with no matching brand, a reservation that would collide with the single
 * slot, a booking whose widget shape we cannot build — becomes a links-pool item
 * carrying its provider label rather than being dropped.
 *
 * COORD: `manual:{sha1(strtolower(trim(url)))}`, deliberately the URL and not a
 * uuid — byte-identical to what the retired custom-link backfiller and PoolItemCreateController
 * mint, so a link that arrives twice through two different doors updates ONE item
 * instead of forking. Two manual coords carrying one url would also poison that
 * url as a joining key for the whole resolution run (Resolver::poisonedKeys drops
 * a value a single source contributes twice).
 *
 * favicon and logo ARE carried (2026-08-17, reversing Phase 3's "not carried"):
 * the owner wants the site's share image and favicon on every link card, in the
 * dashboard and on the page. They ride the standard `media` projection —
 * `logo` (og:image) as the `cover` role, `favicon` as the `logo` role — so they
 * mint content.media_assets exactly the way a YouTube thumbnail does: a
 * source_url-only asset that MediaUrlResolver passes through. That IS slice
 * 1a's borrowed lane, and every connector thumbnail already lives there; the
 * Phase 3 concern (a decoration pulling in a whole lane) no longer applies once
 * the lane is the ordinary path. PoolResolver reads them back as `thumbnail`
 * (cover) and `favicon` (logo).
 */
class LinkPoolWriter
{
    public const POOL = 'custom_links';

    public function __construct(
        private readonly ProjectionWriter $writer,
        private readonly PoolSectionProvisioner $sections,
    ) {}

    public static function coordFor(string $url): string
    {
        return 'manual:'.sha1(strtolower(trim($url)));
    }

    /**
     * Write (or update) a link item and pin it. Returns the content item id.
     *
     * $headline falls back to the URL host, matching PoolItemCreateController's
     * hand-add contract: "it appears, titled what you called it".
     */
    /**
     * @param  string|null  $logo  the page's share image (og:image) → cover role
     * @param  string|null  $favicon  the site's icon → logo role
     * @param  bool  $enrich  false ONLY for EnrichPoolLinkJob's own write-back —
     *                        a page that yielded a title and nothing else would
     *                        otherwise re-dispatch the job that just ran, for ever
     */
    public function add(
        User $user,
        string $url,
        ?string $headline = null,
        ?string $description = null,
        ?string $favicon = null,
        ?string $logo = null,
        bool $enrich = true,
    ): string {
        $url = trim($url);
        $coord = self::coordFor($url);
        $userId = (string) $user->id;

        $headline = trim((string) $headline);
        if ($headline === '') {
            $headline = $this->storedHeadline($userId, $coord)
                ?? (string) (parse_url($url, PHP_URL_HOST) ?: $url);
        }

        $projection = [
            'kind' => 'link',
            'headline' => $headline,
            'facets' => [
                'f_text' => ['headline' => $headline],
                'f_link' => ['url' => $url],
            ],
        ];

        // Omitted rather than written null when there is nothing to say — a new
        // item has no stale body to clear (the retired backfiller did the same).
        $description = trim((string) $description);
        if ($description !== '') {
            $projection['facets']['f_text']['body'] = $description;
        }

        // Media only when there is something to say: writeFacets() REPLACES an
        // item's media set per write, so an add carrying no images must not
        // wipe the ones an earlier enrichment already landed.
        $media = [];
        $logo = trim((string) $logo);
        $favicon = trim((string) $favicon);
        if ($logo !== '') {
            $media[] = ['role' => 'cover', 'url' => $logo];
        }
        if ($favicon !== '') {
            $media[] = ['role' => 'logo', 'url' => $favicon];
        }
        if ($media !== []) {
            $projection['media'] = $media;
        }

        $itemId = $this->writer->writeManualItem($userId, $coord, $projection);

        // An explicit add un-deletes, exactly as the hand-add lane does:
        // upsertSourceItem() clears source_items.removed_at, but the user-level
        // delete lives on items.removed_at, which PoolResolver filters on.
        // Without this the owner re-adds a link they removed, gets a success,
        // and nothing appears, with no route back.
        DB::connection('pgsql')->table('content.items')
            ->where('id', $itemId)
            ->where('user_id', $userId)
            ->whereNotNull('removed_at')
            ->update(['removed_at' => null, 'updated_at' => now()]);

        $site = $user->site;
        if ($site instanceof Site) {
            $this->pin($site, $itemId);
            SiteCacheLanes::bust([(string) $site->id]);
        }

        // Read the page for the things the caller did not bring (owner,
        // 2026-08-19). Every writer that reaches this method used to decide for
        // itself whether to enrich, and most did not: the ordering fallbacks,
        // the Google harvest and the Phase-3 backfill all wrote a bare
        // host-titled row, so Ruh's three Bopple links sat with no favicon, no
        // share image and no description while a dashboard-added link had all
        // three. Deciding it HERE means every lane gets it, including lanes
        // written later.
        //
        // Only when this write brought neither images nor a body — a caller
        // that already checked the page has nothing to gain from a second
        // fetch — and never from the job's own write-back ($enrich false),
        // which is what stops a title-only page looping through here.
        if ($enrich && $media === [] && $description === '' && $url !== '') {
            EnrichPoolLinkJob::dispatch($userId, $url)->afterCommit();
        }

        return $itemId;
    }

    /**
     * Pin at the end, FORCING the state. Find-or-new rather than exists-check:
     * the identity lane can hand back an item that already carries curation, and
     * site.section_items is UNIQUE (section_id, item_id) across BOTH states — so
     * testing only for existence would skip an `excluded` row and leave the item
     * excluded, an add that silently does nothing (PoolItemCreateController's
     * own note).
     */
    private function pin(Site $site, string $itemId): void
    {
        $section = $this->sections->ensure($site, self::POOL);
        $sectionId = (string) $section->id;

        $pin = SectionItem::query()
            ->where('section_id', $sectionId)
            ->where('item_id', $itemId)
            ->first() ?? new SectionItem;

        $pin->section_id = $sectionId;
        $pin->item_id = $itemId;
        $pin->state = SectionItem::STATE_PINNED;
        $pin->sort_key ??= $this->nextSortKey($sectionId);
        if (! $pin->exists) {
            $pin->created_at = now();
        }
        $pin->save();
    }

    /** The headline this coord already resolved to, so a title-less re-add does not clobber it. */
    private function storedHeadline(string $userId, string $coord): ?string
    {
        $headline = DB::connection('pgsql')->table('content.source_items as si')
            ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
            ->join('content.items as i', 'i.id', '=', 'si.item_id')
            ->where('cs.user_id', $userId)
            ->where('cs.kind', 'manual')
            ->where('si.coord', $coord)
            ->value('i.headline_cache');

        return is_string($headline) && $headline !== '' ? $headline : null;
    }

    private function nextSortKey(string $sectionId): float
    {
        $highest = SectionItem::query()
            ->where('section_id', $sectionId)
            ->where('state', SectionItem::STATE_PINNED)
            ->max('sort_key');

        return $highest === null ? 1.0 : ((float) $highest) + 1.0;
    }
}
