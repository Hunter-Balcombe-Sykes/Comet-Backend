<?php

namespace App\Services\Content;

use App\Ingest\Projection\ProjectionWriter;
use App\Models\Core\Site\SectionItem;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Site\Documents\SiteCacheLanes;
use App\Site\Pools\PoolRegistry;
use App\Site\Pools\PoolSectionProvisioner;
use Illuminate\Support\Facades\DB;

/**
 * Convergence Phase 6: the live write lane for a HAND-ADDED event — the one
 * EventsCatalog used to store as a `partna.manual_event` connection.
 *
 * The shape is LinkPoolWriter's, one pool over: same manual source, same
 * url-derived coord, same pin-at-the-end curation. It is a separate class
 * because the projection differs (kind `event`, not `link`) and because the two
 * lanes' curation rules are not the same — see the pin note below.
 *
 * This is code-only on dev: `partna.manual_event` held 0 live rows (1
 * soft-deleted) when the path moved, so there is no data half to reconcile.
 *
 * WHY PINNED, given the events pool's rule is `kind_is` + `upcoming_occurrence`
 * and a hand-added event carries no dates: a pin is added unconditionally
 * alongside the rule candidates (PoolResolver::resolve), so the item shows.
 * That is the correct outcome and not a loophole — the owner typed this link
 * and said it is an event; the occurrence filter exists to retire events that
 * have HAPPENED, and one with no date has not. The legacy lane behaved the same
 * way (a custom card with null startDate survived dropElapsed()).
 */
class ManualEventWriter
{
    public const POOL = 'events';

    public function __construct(
        private readonly ProjectionWriter $writer,
        private readonly PoolSectionProvisioner $sections,
    ) {}

    /**
     * Byte-identical to LinkPoolWriter::coordFor, deliberately: the coord is
     * the url on this user's ONE manual source, so a link added once as an
     * event and once as a link must resolve to the same coord or the url is
     * poisoned as a joining key for the whole resolution run
     * (Resolver::poisonedKeys drops a value one source contributes twice).
     */
    public static function coordFor(string $url): string
    {
        return 'manual:'.sha1(strtolower(trim($url)));
    }

    /**
     * Write (or update) a hand-added event and pin it. Returns the content item id.
     *
     * @return array{id: string, name: string}|null null when the user has no
     *                                              site — a pool item needs a
     *                                              section, which hangs off the
     *                                              site.
     */
    public function add(User $user, string $url, ?string $name = null, ?string $description = null): ?array
    {
        $site = $user->site;
        if (! $site instanceof Site) {
            return null;
        }

        $url = trim($url);
        $coord = self::coordFor($url);
        $userId = (string) $user->id;

        $headline = trim((string) $name);
        if ($headline === '') {
            $headline = (string) (parse_url($url, PHP_URL_HOST) ?: $url);
        }

        $projection = [
            'kind' => 'event',
            'headline' => $headline,
            'facets' => [
                'f_text' => ['headline' => $headline],
                'f_link' => ['url' => $url],
            ],
        ];

        // Omitted rather than written null when there is nothing to say — a new
        // item has no stale body to clear (LinkPoolWriter does the same).
        $description = trim((string) $description);
        if ($description !== '') {
            $projection['facets']['f_text']['body'] = $description;
        }

        $itemId = $this->writer->writeManualItem($userId, $coord, $projection);

        // An explicit add un-deletes, exactly as the hand-add lane does:
        // upsertSourceItem() clears source_items.removed_at, but the user-level
        // delete lives on items.removed_at, which PoolResolver filters on.
        DB::connection('pgsql')->table('content.items')
            ->where('id', $itemId)
            ->where('user_id', $userId)
            ->whereNotNull('removed_at')
            ->update(['removed_at' => null, 'updated_at' => now()]);

        $this->pin($site, $itemId);
        SiteCacheLanes::bust([(string) $site->id]);

        return ['id' => $itemId, 'name' => $headline];
    }

    /**
     * The owner's hand-added events, in pin order.
     *
     * @return list<array{id: string, name: ?string, description: ?string, url: ?string}>
     */
    public function cards(User $user): array
    {
        $site = $user->site;
        if (! $site instanceof Site) {
            return [];
        }

        $sectionId = DB::connection('pgsql')->table('site.sections')
            ->where('site_id', $site->id)
            ->where('key', PoolRegistry::sectionKey(self::POOL))
            ->value('id');

        if ($sectionId === null) {
            return [];
        }

        // Scoped to the MANUAL source: the events pool also carries Eventbrite
        // and Humanitix items projected from connections, and those keep their
        // own connection-backed entries in EventsCatalog::selection(). Listing
        // them here too would show every synced event twice.
        return DB::connection('pgsql')->table('site.section_items as si')
            ->join('content.items as i', 'i.id', '=', 'si.item_id')
            ->join('content.source_items as csi', 'csi.item_id', '=', 'i.id')
            ->join('content.sources as cs', function ($join) {
                $join->on('cs.id', '=', 'csi.source_id')->where('cs.kind', '=', 'manual');
            })
            ->leftJoin('content.f_link as fl', 'fl.item_id', '=', 'i.id')
            ->leftJoin('content.f_text as ft', 'ft.item_id', '=', 'i.id')
            ->where('si.section_id', $sectionId)
            ->where('si.state', SectionItem::STATE_PINNED)
            ->where('i.kind', 'event')
            ->whereNull('i.removed_at')
            ->orderBy('si.sort_key')
            ->distinct()
            ->get(['i.id', 'i.headline_cache', 'fl.url', 'ft.body'])
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'name' => $row->headline_cache,
                'description' => $row->body,
                'url' => $row->url,
            ])
            ->all();
    }

    /**
     * Remove one hand-added event. Returns false when it is not the owner's.
     *
     * items.removed_at, not an `excluded` curation row — same reasoning as
     * LinkPoolReader::remove(): the owner deleting an event they typed means
     * "this is not mine any more", and the pool's rule would re-select a merely
     * unpinned item straight back onto the page.
     */
    public function remove(User $user, string $itemId): bool
    {
        if (! collect($this->cards($user))->contains(fn (array $card) => $card['id'] === $itemId)) {
            return false;
        }

        DB::connection('pgsql')->table('content.items')
            ->where('id', $itemId)
            ->where('user_id', (string) $user->id)
            ->update(['removed_at' => now(), 'updated_at' => now()]);

        $site = $user->site;
        if ($site instanceof Site) {
            SiteCacheLanes::bust([(string) $site->id]);
        }

        return true;
    }

    /**
     * Pin at the end, FORCING the state — find-or-new rather than
     * exists-check, because the identity lane can hand back an item that
     * already carries curation and site.section_items is UNIQUE
     * (section_id, item_id) across BOTH states. Testing only for existence
     * would skip an `excluded` row and leave the item excluded: an add that
     * silently does nothing.
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

    private function nextSortKey(string $sectionId): float
    {
        $highest = SectionItem::query()
            ->where('section_id', $sectionId)
            ->where('state', SectionItem::STATE_PINNED)
            ->max('sort_key');

        return $highest === null ? 1.0 : ((float) $highest) + 1.0;
    }
}
