<?php

namespace App\Services\Content;

use App\Ingest\Projection\ProjectionWriter;
use App\Ingest\Projection\SchemaOrgEventProjector;
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
 * Slice 7 Phase 4 gave it a second arm, addStandalone(): the SCRAPED single
 * event that used to be a `resource_kind='event'` connection. Same coord, same
 * pin, same pool — only the projection is richer, because a scraped event has
 * dates, a venue and a price where a hand-added one has none.
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

    /**
     * How many events one owner may hand-add or add-by-URL.
     *
     * PRESERVED across the move onto the pool, not retired: idempotency on a
     * deterministic coord stops duplicates of the SAME event, and does nothing
     * about an unbounded number of DIFFERENT ones. That is what this limits,
     * and relaxing it is a product decision rather than a consequence of
     * changing where the row is stored.
     *
     * Two collapses were forced by the destination, and both TIGHTEN — never
     * loosen — which is the safe direction for an abuse-surface control:
     *
     *  1. PER-PLATFORM → PER-OWNER. `EventsCatalog::MAX_EVENTS` and
     *     `EventsPlatformController::MAX_STANDALONE_EVENTS` were both 10 per
     *     platform, so an owner could hold 20 across eventbrite + humanitix.
     *     A pool item carries no platform, so per-owner is the only form the
     *     limit can take here.
     *  2. It now counts every owner-authored event in the pool, including
     *     hand-added link cards, which were uncapped before. The pool keeps no
     *     marker for which lane wrote an item — that indistinguishability is
     *     the point of converging them — so the count cannot be narrowed
     *     without reintroducing one.
     */
    public const MAX_STANDALONE_EVENTS = 10;

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
     * The scraped standalone-event payload → `event` projection mapping.
     *
     * Slice 7 Phase 4. Deliberately shaped to agree with
     * {@see SchemaOrgEventProjector} facet-for-facet:
     * the SAME event can also arrive through the Eventbrite/Humanitix
     * connectors when the owner later connects the organiser, and two
     * projections of one event that disagree about `f_occurrence` would
     * resolve into a contradictory item rather than merging cleanly.
     *
     * `image` rides the standard `media` projection as the `cover` role —
     * a source_url-only content.media_assets row, exactly what
     * SchemaOrgEventProjector emits for the same event via a connected
     * organiser and what LinkPoolWriter emits for a link's og:image. This
     * used to drop it under Phase 3's "no third-party image assets" ruling;
     * LinkPoolWriter reversed that for links, the connector lane never had
     * it, and "agrees facet-for-facet with SchemaOrgEventProjector" was
     * simply untrue on media (2026-08-18).
     *
     * Public + static because StandaloneEventBackfiller writes the SAME shape
     * for the rows that predate the cutover — one mapper, so the backfill and
     * the live path cannot drift.
     *
     * @param  array<string, mixed>  $event  EventsPayload::standalonePayload's shape
     * @return array<string, mixed>
     */
    public static function projectStandalone(array $event, string $url): array
    {
        $name = trim((string) ($event['name'] ?? ''));
        $headline = $name !== '' ? $name : (string) (parse_url($url, PHP_URL_HOST) ?: $url);

        // startsAt/endsAt are the same strings under different keys in the
        // stored payload; either may be the one present.
        $start = self::isoOrNull($event['startDate'] ?? $event['startsAt'] ?? null);
        $end = self::isoOrNull($event['endDate'] ?? $event['endsAt'] ?? null);

        $occurrence = array_filter([
            'starts_at_local' => $start,
            'ends_at_local' => $end,
            'starts_at_utc' => self::toUtc($start),
            'zone_confidence' => $start === null ? null : 'offset_only',
        ], static fn ($v) => $v !== null);

        $place = array_filter([
            'venue_name' => self::trimmedOrNull($event['venue'] ?? null),
            'locality' => self::trimmedOrNull($event['location'] ?? null),
        ], static fn ($v) => $v !== null);

        $text = ['headline' => $headline];
        $description = self::trimmedOrNull($event['description'] ?? null);
        if ($description !== null) {
            $text['body'] = $description;
        }

        $priceMin = is_numeric($event['priceMin'] ?? null) ? (float) $event['priceMin'] : null;
        $currency = self::trimmedOrNull($event['currency'] ?? null);

        $image = self::trimmedOrNull($event['image'] ?? null);
        $image = $image !== null && preg_match('#^https?://#i', $image) === 1 ? $image : null;

        return [
            'kind' => 'event',
            'headline' => $headline,
            'media' => $image === null ? [] : [['role' => 'cover', 'url' => $image]],
            'facets' => array_filter([
                'f_text' => $text,
                'f_link' => ['url' => $url],
                'f_occurrence' => $occurrence === [] ? null : $occurrence,
                'f_place' => $place === [] ? null : $place,
            ]),
            // The scrape sees the LOWEST tier of a multi-tier ticket set, so
            // `from` is the only honest qualifier — SchemaOrgEventProjector's
            // own reasoning, reused rather than re-decided.
            'offers' => $priceMin === null ? [] : [[
                'amount_minor' => (int) round($priceMin * 100),
                'currency' => $currency,
                'qualifier' => $priceMin === 0.0 ? 'free' : 'from',
                'url' => $url,
            ]],
        ];
    }

    /**
     * Write (or update) a standalone event scraped from its own URL, and pin
     * it. The live counterpart of StandaloneEventBackfiller — same coord, same
     * projection, so an event added today and one migrated yesterday are the
     * same row.
     *
     * @param  array<string, mixed>  $event  EventsPayload::standalonePayload's shape
     * @return array{id: string, name: string}|null null when the user has no site
     */
    public function addStandalone(User $user, string $url, array $event): ?array
    {
        return $this->write($user, trim($url), static fn (string $u) => self::projectStandalone($event, $u));
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
        return $this->write($user, trim($url), static function (string $u) use ($name, $description): array {
            $headline = trim((string) $name);
            if ($headline === '') {
                $headline = (string) (parse_url($u, PHP_URL_HOST) ?: $u);
            }

            $projection = [
                'kind' => 'event',
                'headline' => $headline,
                'facets' => [
                    'f_text' => ['headline' => $headline],
                    'f_link' => ['url' => $u],
                ],
            ];

            // Omitted rather than written null when there is nothing to say — a
            // new item has no stale body to clear (LinkPoolWriter does the same).
            $description = trim((string) $description);
            if ($description !== '') {
                $projection['facets']['f_text']['body'] = $description;
            }

            return $projection;
        });
    }

    /**
     * The shared write: coord from the URL, upsert through the manual lane,
     * un-delete, pin, bust. $project builds the projection from the trimmed URL.
     *
     * @param  callable(string): array<string, mixed>  $project
     * @return array{id: string, name: string}|null
     */
    private function write(User $user, string $url, callable $project): ?array
    {
        $site = $user->site;
        if (! $site instanceof Site) {
            return null;
        }

        $coord = self::coordFor($url);
        $userId = (string) $user->id;
        $projection = $project($url);
        $headline = (string) $projection['headline'];

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
     * Live `event`-kind items on this owner's OWN manual source.
     *
     * Scoped to the manual source deliberately. The events pool also carries
     * items the Eventbrite/Humanitix connectors project from a connected
     * ORGANISER, and counting those against the cap would let one organiser
     * with thirty upcoming events block every hand-add — a limit on what the
     * owner typed must not be spent by what a connector fetched.
     *
     * Not `cards()`: that is pin-scoped, so an EXCLUDED event (the owner hid
     * it) would stop counting and quietly raise the ceiling.
     */
    public function ownedEventCount(User $user): int
    {
        return DB::connection('pgsql')->table('content.items as i')
            ->join('content.source_items as csi', 'csi.item_id', '=', 'i.id')
            ->join('content.sources as cs', function ($join) {
                $join->on('cs.id', '=', 'csi.source_id')->where('cs.kind', '=', 'manual');
            })
            ->where('i.user_id', (string) $user->id)
            ->where('i.kind', 'event')
            ->whereNull('i.removed_at')
            ->distinct()
            ->count('i.id');
    }

    /**
     * Would adding this URL push the owner past the cap?
     *
     * False when the URL already resolves to one of their live items: the
     * legacy cap only ever fired for a NEW event, so a re-add — which is an
     * UPDATE on a stable coord, and is how a refreshed event re-lands — must
     * keep working at the ceiling. Losing that would make every re-add fail
     * the moment an owner filled their tenth slot.
     *
     * Read-then-write, with no lock: the legacy per-platform lock went with
     * the connection write it protected, so two exactly-simultaneous adds can
     * both pass here and land an eleventh event. A soft ceiling on
     * owner-authored cards is not a control worth taking a cross-lane lock
     * for; the same window has existed on the hand-add arm since convergence
     * Phase 6.
     */
    public function wouldExceedCap(User $user, string $url): bool
    {
        if ($this->ownedEventCount($user) < self::MAX_STANDALONE_EVENTS) {
            return false;
        }

        $held = DB::connection('pgsql')->table('content.items as i')
            ->join('content.source_items as csi', 'csi.item_id', '=', 'i.id')
            ->join('content.sources as cs', function ($join) {
                $join->on('cs.id', '=', 'csi.source_id')->where('cs.kind', '=', 'manual');
            })
            ->where('i.user_id', (string) $user->id)
            ->where('i.kind', 'event')
            ->whereNull('i.removed_at')
            ->where('csi.coord', self::coordFor(trim($url)))
            ->exists();

        return ! $held;
    }

    /**
     * The owner's pool-lane events, in pin order.
     *
     * Slice 7 Phase 4 widened this past `{name, description, url}`: a migrated
     * standalone event carries real dates, a venue and a price, and the
     * dashboard's Tickets & Events card renders all three. Returning nulls for
     * them would have made the repointed add-verb a visible regression on the
     * one screen that reads this.
     *
     * A HAND-added event still returns nulls for the dated half, which is
     * unchanged behaviour — it never had them.
     *
     * @return list<array{id: string, name: ?string, description: ?string, url: ?string, venue: ?string, location: ?string, startDate: ?string, endDate: ?string, priceMin: ?float, currency: ?string}>
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
            ->leftJoin('content.f_occurrence as fo', 'fo.item_id', '=', 'i.id')
            ->leftJoin('content.f_place as fp', 'fp.item_id', '=', 'i.id')
            // One offer per migrated event by construction (projectStandalone
            // emits at most one), so a left join cannot fan the row out.
            ->leftJoin('content.offers as o', 'o.item_id', '=', 'i.id')
            ->where('si.section_id', $sectionId)
            ->where('si.state', SectionItem::STATE_PINNED)
            ->where('i.kind', 'event')
            ->whereNull('i.removed_at')
            ->orderBy('si.sort_key')
            ->distinct()
            ->get([
                'i.id', 'i.headline_cache', 'fl.url', 'ft.body',
                'fo.starts_at_local', 'fo.ends_at_local',
                'fp.venue_name', 'fp.locality',
                'o.amount_minor', 'o.currency',
            ])
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'name' => $row->headline_cache,
                'description' => $row->body,
                'url' => $row->url,
                'venue' => $row->venue_name,
                'location' => $row->locality,
                'startDate' => $row->starts_at_local,
                'endDate' => $row->ends_at_local,
                'priceMin' => $row->amount_minor === null ? null : ((int) $row->amount_minor) / 100,
                'currency' => $row->currency,
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

    private static function trimmedOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** A stored date is a string or nothing — never coerce a number into one. */
    private static function isoOrNull(mixed $value): ?string
    {
        return self::trimmedOrNull($value);
    }

    /**
     * ISO-with-offset → UTC ISO; null when unparseable.
     *
     * Pure string math on the offset the vendor embedded, exactly as
     * SchemaOrgEventProjector::toUtc does it — the IANA zone is unknowable from
     * an offset alone, which is why zone_confidence stays 'offset_only'.
     */
    private static function toUtc(?string $iso): ?string
    {
        if ($iso === null) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($iso))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z');
        } catch (\Exception) {
            return null;
        }
    }
}
