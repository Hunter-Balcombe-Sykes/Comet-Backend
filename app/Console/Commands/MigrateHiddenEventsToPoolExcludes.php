<?php

namespace App\Console\Commands;

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\SectionItem;
use App\Models\Core\Site\Site;
use App\Services\Platforms\EventsPayload;
use App\Site\Documents\BuildState;
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

        foreach ($hiddenByUser as $userId => $hiddenIds) {
            try {
                $itemIds = $this->itemIdsForHiddenIds($userId, $hiddenIds);
                $unmatched += count($hiddenIds) - count($itemIds);

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
                    // there is simply no Events page for it to affect.
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
                    if ($row !== null && $row->state === SectionItem::STATE_PINNED) {
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
     * The user's live event items whose link hashes to one of $hiddenIds.
     *
     * @param  list<string>  $hiddenIds
     * @return list<string>
     */
    private function itemIdsForHiddenIds(string $userId, array $hiddenIds): array
    {
        $wanted = array_flip($hiddenIds);
        $matched = [];

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
            return [];
        }

        DB::connection('pgsql')->table('content.f_link')
            ->whereIn('item_id', $itemIds)
            ->select(['item_id', 'url'])
            ->cursor()
            ->each(function (object $link) use ($wanted, &$matched) {
                $url = (string) ($link->url ?? '');
                if ($url === '') {
                    return;
                }
                if (isset($wanted[EventsPayload::id($url)])) {
                    $matched[(string) $link->item_id] = true;
                }
            });

        return array_keys($matched);
    }
}
