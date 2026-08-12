<?php

namespace App\Site\Pools;

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\SectionItem;
use App\Models\Core\Site\Site;
use App\Services\Platforms\EventsPayload;
use App\Site\Documents\BuildState;
use Illuminate\Support\Facades\DB;

/**
 * Keep a legacy "hide this event" in step with the events pool.
 *
 * The dashboard hides an event by recording its id in the account row's
 * `hiddenEventIds`, and the LEGACY lane honoured that by pruning the event out
 * of the stored payload at write time. The pool lane does not read that
 * payload at all — it reads content.items, which the connectors land whether
 * an owner hid the event or not. So once the Events page is pool-derived, a
 * hide that only writes `hiddenEventIds` is a button that does nothing.
 *
 * content:migrate-hidden-events carried the hides that already existed. This
 * class is the ONGOING half: without it the migration is a one-shot and every
 * hide made afterwards silently fails. Same relationship
 * ProjectionWriter::refreshItemCaches() has to content:backfill-item-slugs.
 *
 * The join is EventsPayload::id() — `substr(sha1(strtolower(trim($link))), 0, 16)`,
 * a pure function of the canonical event link — hashed over each live event
 * item's own f_link URL. That is the same rule the migration command uses, and
 * it is the invariant the two must never disagree on: if this hash changes,
 * both change together or hides stop matching.
 */
class EventExcludeSync
{
    /**
     * Exclude the pool item behind a legacy event id.
     *
     * Returns false when no live event item carries a link hashing to $eventId
     * — a standalone event (which lands no content item), an event the
     * connector has not projected, or one already retired. The caller should
     * treat false as "the legacy hide stands alone", not as an error.
     */
    public function excludeByLegacyEventId(Site $site, string $userId, string $eventId): bool
    {
        $itemId = $this->itemIdForLegacyEventId($userId, $eventId);
        if ($itemId === null) {
            return false;
        }

        $section = app(PoolSectionProvisioner::class)->ensure($site, 'events');

        $row = SectionItem::query()
            ->where('section_id', $section->id)
            ->where('item_id', $itemId)
            ->first() ?? new SectionItem;

        $row->section_id = (string) $section->id;
        $row->item_id = $itemId;
        $row->state = SectionItem::STATE_EXCLUDED;
        // An exclude carries no position — leaving a stale sort_key behind
        // would resurface it in pin order if the row is ever promoted back.
        $row->sort_key = null;
        if (! $row->exists) {
            $row->created_at = now();
        }
        $row->save();

        // SectionItem has no observer, so all three invalidation lanes are by
        // hand (spec §9.2): the document build state, the payload cache key
        // that composes from sites.updated_at, and the edge.
        BuildState::bump((string) $site->id);
        DB::connection('pgsql')->table('site.sites')
            ->where('id', $site->id)->update(['updated_at' => now()]);
        if ((string) ($site->subdomain ?? '') !== '') {
            CloudflareCachePurgeJob::dispatch($site->subdomain);
        }

        return true;
    }

    /** The user's live event item whose link hashes to $eventId, if any. */
    public function itemIdForLegacyEventId(string $userId, string $eventId): ?string
    {
        $itemIds = DB::connection('pgsql')->table('content.items')
            ->where('user_id', $userId)
            ->where('kind', 'event')
            ->whereNull('removed_at')
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if ($itemIds === []) {
            return null;
        }

        $match = null;

        // Two-step and hashed in PHP: SQLite has no sha1(), so a join would
        // pass here and fail in the mirror — the same reason the migration
        // command does it this way.
        DB::connection('pgsql')->table('content.f_link')
            ->whereIn('item_id', $itemIds)
            ->select(['item_id', 'url'])
            ->cursor()
            ->each(function (object $link) use ($eventId, &$match) {
                $url = (string) ($link->url ?? '');
                if ($url !== '' && EventsPayload::id($url) === $eventId) {
                    $match = (string) $link->item_id;

                    return false; // stop the cursor
                }

                return true;
            });

        return $match;
    }
}
