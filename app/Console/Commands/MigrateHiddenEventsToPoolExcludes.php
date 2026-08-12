<?php

namespace App\Console\Commands;

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\SectionItem;
use App\Models\Core\Site\Site;
use App\Services\Platforms\EventsPayload;
use App\Site\Documents\BuildState;
use App\Site\Pools\EventExcludeSync;
use App\Site\Pools\PoolSectionProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Carry each owner's `hiddenEventIds` curation over to the events pool as
 * `site.section_items` excludes (slice 2, Task 9 Step 3).
 *
 * WHY this is not optional. The legacy lane hides at WRITE time —
 * EventsPayload::accountPayload() prunes hidden events out of `upcoming[]`
 * before the payload is stored, which is why the public wire never carries
 * `hiddenEventIds` and never had to. The pool lane does not read that payload
 * at all: it reads content.items, which the ingest connectors land straight
 * from the platform, hidden or not. So an owner who hid an event sees it
 * reappear the moment the pool becomes their Events page — unless this runs.
 *
 * HOW the mapping works, and why it is not the payload join. A hidden event
 * is ABSENT from `upcoming[]` by construction, so its URL cannot be recovered
 * from the connection. It does not need to be: EventsPayload::id() is
 * `substr(sha1(strtolower(trim($link))), 0, 16)` — a pure function of the
 * canonical event link. Hashing each content item's own f_link URL reproduces
 * exactly the id the dashboard stored (verified against dev: the item_key
 * a53103b7e77fd64f is sha1 of the Connect Sydney event's link). The join is
 * therefore total for any event carrying a link, hidden or not.
 *
 * Idempotent: the exclude row is keyed (section_id, item_id) and re-running
 * updates rather than duplicates. Read-only under --dry-run.
 */
class MigrateHiddenEventsToPoolExcludes extends Command
{
    protected $signature = 'content:migrate-hidden-events
        {--dry-run : Report what would be excluded without writing}
        {--user= : Only this user id}';

    protected $description = 'Migrate legacy hiddenEventIds curation into events-pool excludes';

    /** The platforms whose account rows carry hiddenEventIds. */
    private const PLATFORMS = ['eventbrite', 'humanitix'];

    public function handle(PoolSectionProvisioner $provisioner): int
    {
        $dry = (bool) $this->option('dry-run');

        $hiddenByUser = $this->hiddenIdsByUser();
        if ($hiddenByUser === []) {
            $this->info('Hidden events: none to migrate.');

            return self::SUCCESS;
        }

        $excluded = 0;
        $unmatched = 0;
        $failed = 0;
        $noSite = 0;
        $skippedPinned = 0;
        /** @var list<string> $unmatchedDetail */
        $unmatchedDetail = [];

        foreach ($hiddenByUser as $userId => $hiddenIds) {
            try {
                // Keyed by the HIDDEN ID, not just the item ids: two hidden ids
                // can hash to the same item (the same event listed twice), and
                // counting `count($hiddenIds) - count($itemIds)` reported that
                // as an unmatched hide when it was a matched duplicate.
                $matchedByHiddenId = $this->matchHiddenIds($userId, $hiddenIds);
                $itemIds = array_values(array_unique(array_filter($matchedByHiddenId)));

                foreach ($hiddenIds as $hiddenId) {
                    if (($matchedByHiddenId[$hiddenId] ?? null) === null) {
                        $unmatched++;
                        $unmatchedDetail[] = "{$userId}/{$hiddenId}";
                    }
                }

                if ($itemIds === []) {
                    continue;
                }

                if ($dry) {
                    $this->line("  + user {$userId}: would exclude ".count($itemIds).' item(s)');
                    $excluded += count($itemIds);

                    continue;
                }

                $site = Site::query()->where('user_id', $userId)->first();
                if ($site === null) {
                    // Curation with no site to apply it to. Not an error —
                    // there is simply no Events page for it to affect — but it
                    // IS an unapplied hide, so it must not be silently folded
                    // into the success line.
                    $noSite += count($itemIds);
                    $this->warn("  ! user {$userId}: ".count($itemIds).' hide(s) not applied — no site');

                    continue;
                }

                $section = $provisioner->ensure($site, 'events');

                foreach ($itemIds as $itemId) {
                    $row = SectionItem::query()
                        ->where('section_id', $section->id)
                        ->where('item_id', $itemId)
                        ->first();

                    // A PIN beats a legacy hide: the owner picked this item in
                    // the new lane more recently than they hid it in the old.
                    // Reported, not swallowed — it is a hide that deliberately
                    // did not apply, and the operator should see the count.
                    if ($row !== null && $row->state === SectionItem::STATE_PINNED) {
                        $skippedPinned++;
                        $this->warn("  ! user {$userId}: hide skipped for item {$itemId} — pinned in the pool");

                        continue;
                    }

                    $row ??= new SectionItem;
                    $row->section_id = (string) $section->id;
                    $row->item_id = (string) $itemId;
                    $row->state = SectionItem::STATE_EXCLUDED;
                    $row->sort_key = null;
                    if (! $row->exists) {
                        $row->created_at = now();
                    }
                    $row->save();
                    $excluded++;
                }

                // SectionItem is Eloquent but carries no observer, so the
                // document bump, the payload-cache key and the edge purge are
                // all by hand — same three the pool controller does, plus the
                // sites.updated_at touch it omits.
                BuildState::bump((string) $site->id);
                DB::connection('pgsql')->table('site.sites')
                    ->where('id', $site->id)->update(['updated_at' => now()]);
                if ((string) ($site->subdomain ?? '') !== '') {
                    CloudflareCachePurgeJob::dispatch($site->subdomain);
                }
            } catch (\Throwable $e) {
                report($e);
                $failed++;
                $this->warn("  ! user {$userId}: {$e->getMessage()}");
            }
        }

        $verb = $dry ? 'would exclude' : 'excluded';
        $this->info("Hidden events: {$verb} {$excluded}, unmatched {$unmatched}".($failed > 0 ? ", {$failed} failed" : '.'));

        // A hide that did not land is the whole risk of this migration — the
        // owner believes the event is hidden and the pool will show it. Saying
        // "success" and burying the number in an info line is how that gets
        // missed, so every non-applied hide is warned about by name.
        $notApplied = $unmatched + $noSite + $skippedPinned;
        if ($notApplied > 0) {
            $this->warn("  {$notApplied} hide(s) did NOT reach the pool: {$unmatched} unmatched, {$noSite} no-site, {$skippedPinned} pinned-in-pool.");
            $this->warn('  Those events will be VISIBLE on the pool-derived Events page. Review before treating this as done.');
            foreach (array_slice($unmatchedDetail, 0, 20) as $detail) {
                $this->warn("    unmatched: {$detail}");
            }
            if (count($unmatchedDetail) > 20) {
                $this->warn('    … '.(count($unmatchedDetail) - 20).' more');
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * user_id => the hidden event ids across all their events connections.
     *
     * @return array<string, list<string>>
     */
    private function hiddenIdsByUser(): array
    {
        $out = [];

        DB::connection('pgsql')->table('site.platform_connections')
            ->whereIn('platform', self::PLATFORMS)
            ->whereNull('deleted_at')
            ->when($this->option('user'), fn ($q, $u) => $q->where('user_id', $u))
            ->orderBy('id')
            ->select(['user_id', 'payload'])
            ->cursor()
            ->each(function (object $row) use (&$out) {
                $payload = is_string($row->payload) ? json_decode($row->payload, true) : $row->payload;
                $hidden = is_array($payload) && is_array($payload['hiddenEventIds'] ?? null)
                    ? $payload['hiddenEventIds']
                    : [];

                foreach ($hidden as $id) {
                    if (is_string($id) && $id !== '') {
                        $out[(string) $row->user_id][] = $id;
                    }
                }
            });

        return array_map(fn (array $ids) => array_values(array_unique($ids)), $out);
    }

    /**
     * hidden id => the live event item whose link hashes to it, or null.
     *
     * Keyed by hidden id rather than returning a bare item list so the caller
     * can tell a genuinely unmatched hide from two hides landing on one item.
     *
     * The hash rule is EventsPayload::id() and it is shared with
     * {@see EventExcludeSync}, which does the same join for
     * hides made after this migration runs. If one changes, both change.
     *
     * @param  list<string>  $hiddenIds
     * @return array<string, string|null>
     */
    private function matchHiddenIds(string $userId, array $hiddenIds): array
    {
        $wanted = array_flip($hiddenIds);
        $matched = [];
        $byHiddenId = array_fill_keys($hiddenIds, null);

        // JOIN-free two-step for the SQLite mirror's sake, and because the
        // hash has to happen in PHP either way — there is no sha1 in SQLite.
        $itemIds = DB::connection('pgsql')->table('content.items')
            ->where('user_id', $userId)
            ->where('kind', 'event')
            ->whereNull('removed_at')
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if ($itemIds === []) {
            return $byHiddenId;
        }

        DB::connection('pgsql')->table('content.f_link')
            ->whereIn('item_id', $itemIds)
            ->select(['item_id', 'url'])
            ->cursor()
            ->each(function (object $link) use ($wanted, &$matched, &$byHiddenId) {
                $url = (string) ($link->url ?? '');
                if ($url === '') {
                    return;
                }
                $hashed = EventsPayload::id($url);
                if (isset($wanted[$hashed])) {
                    $matched[(string) $link->item_id] = true;
                    $byHiddenId[$hashed] = (string) $link->item_id;
                }
            });

        return $byHiddenId;
    }
}
