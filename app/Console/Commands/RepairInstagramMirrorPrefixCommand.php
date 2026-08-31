<?php

namespace App\Console\Commands;

use App\Jobs\Platforms\DeleteMirroredMediaJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Media\MediaDiskResolver;
use App\Services\Platforms\InstagramConnectionSeeder;
use App\Services\Platforms\IntegrationConnectionCacheRefresher;
use App\Services\Platforms\Registry\Platform;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Backfill for E13 (`4feced1b6`): move every Instagram mirror that still lives
 * under the old clock-keyed prefix to its per-connection prefix.
 *
 * `InstagramConnectionSeeder::mirrorFolder()` now keys on the connection uuid,
 * but that only stopped NEW collisions. Every row written before it still
 * carries `payload._folder = 'platforms/instagram/'.created_at->timestamp`, and
 * the two pairs found live on dev — aerial-studio/mr-bap under 1787835720,
 * melbourne-acupuncture/the-cobblers-last under 1788085840 — are still each
 * serving one prefix between two people. Both original harms are still live on
 * those rows: one account publishes the other's face, and disconnecting either
 * dispatches DeleteMirroredMediaJob on the shared folder and deletes the other
 * account's media.
 *
 * Ordering rules that the correctness of a re-run rests on:
 *
 *   1. DB first. The whole plan is built from one query before a single storage
 *      call, exactly as media:gc-orphaned-platform-media does, so a partial DB
 *      read can never leave half a folder migrated.
 *   2. Copy, verify, THEN write the payload. A payload rewritten ahead of a
 *      verified copy would point at an object that does not exist, and the
 *      source is reclaimed afterwards, so there would be nothing to re-run from.
 *   3. Reclaim the source only once EVERY connection claiming it has migrated.
 *      A contested prefix has two claimants; freeing it after the first would
 *      delete the bytes the second one still has to copy.
 *
 * Re-runnable by construction: a row whose `_folder` already equals
 * `mirrorFolder()` is not selected, so a second pass over a finished estate is a
 * no-op, and a pass over a half-finished one resumes it. Copies overwrite, so a
 * run interrupted between copy and payload write simply redoes both.
 *
 * WHAT THIS COMMAND CANNOT DO: give a contested pair back their own faces. Only
 * one account's bytes are under a shared prefix — the last one to mirror won —
 * so copying gives both accounts an isolated prefix holding the SAME picture.
 * That closes the delete-a-neighbour harm and stops the two rows from drifting
 * further apart, but the wrong-face harm on the loser of the race is only
 * repaired by a re-scrape. Those rows are named in their own section of the
 * report rather than folded into the moved count, because a backfill that
 * reported "4 repaired" while two of them still showed a stranger's face would
 * be the more expensive kind of wrong.
 */
class RepairInstagramMirrorPrefixCommand extends Command
{
    protected $signature = 'media:repair-instagram-mirror-prefix
        {--dry-run : Report the plan without copying an object or writing a payload}
        {--limit= : Max source prefixes to migrate this run (a prefix and all of its claimants move together)}
        {--no-reclaim : Leave the emptied source prefixes in place instead of dispatching DeleteMirroredMediaJob}';

    protected $description = 'Move Instagram mirrors off the old clock-keyed R2 prefix onto the per-connection prefix (E13 backfill), and reclaim the emptied folders.';

    /**
     * The fixed filenames InstagramConnectionSeeder::seed() writes, mapped to the
     * payload field that publishes each one. Both halves are needed: the filename
     * to copy, and the field to repoint (or to null, when the object it promised
     * is not actually there).
     */
    private const MIRROR_FILES = [
        'photo.jpg' => 'images',
        'reel.mp4' => 'videoUrl',
        'reel-cover.jpg' => 'videoPoster',
        'profile.jpg' => 'profilePicUrl',
    ];

    // The namespace both schemes live under — the retired token and the current
    // uuid alike. Named for the namespace, not for the old scheme: it is the
    // guard on what this command may copy from and free, not a description of
    // what it is moving away from.
    private const MIRROR_NAMESPACE = 'platforms/instagram/';

    public function handle(IntegrationConnectionCacheRefresher $refresher): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $reclaim = ! (bool) $this->option('no-reclaim');
        $limit = $this->option('limit') !== null ? max(0, (int) $this->option('limit')) : null;

        // Step 1 — the whole plan, from the DB, before any storage call.
        // withTrashed() on purpose: a soft-deleted row keeps its payload, and
        // IntegrationConnectionDisconnectRestoreTest proves a disconnect can be
        // undone. Leaving a trashed row pointed at a shared prefix would re-arm
        // the delete-a-neighbour harm the moment it came back.
        try {
            $rows = IntegrationConnection::withTrashed()
                ->where('platform', Platform::Instagram->value)
                ->orderBy('created_at')
                ->get();
        } catch (Throwable $e) {
            report($e);
            $this->error('Could not read site.platform_connections; nothing was touched.');

            return self::FAILURE;
        }

        $groups = [];
        $alreadyCurrent = 0;
        $neverMirrored = 0;
        $refused = [];

        foreach ($rows as $connection) {
            $target = InstagramConnectionSeeder::mirrorFolder($connection);
            $source = $this->sourcePrefix($connection);

            if ($source === null) {
                // A pending or link-only row that has never had a byte mirrored.
                // Writing it a _folder would invent a prefix that holds nothing.
                $neverMirrored++;

                continue;
            }
            if ($source === $target) {
                $alreadyCurrent++;

                continue;
            }
            if (! str_starts_with($source, self::MIRROR_NAMESPACE)) {
                // Same defence-in-depth as DeleteMirroredMediaJob's prefix guard:
                // this command copies FROM and then reclaims the source, so a
                // payload carrying something outside the instagram namespace is
                // surfaced, never acted on.
                $refused[] = [$connection->getKey(), $source];

                continue;
            }

            $groups[$source][] = ['connection' => $connection, 'target' => $target];
        }

        if ($groups === []) {
            $this->info("Every Instagram mirror is already on its per-connection prefix ({$alreadyCurrent} current, {$neverMirrored} never mirrored).");
            $this->reportRefusals($refused);

            return self::SUCCESS;
        }

        if ($limit !== null) {
            $groups = array_slice($groups, 0, $limit, preserve_keys: true);
        }

        $disk = Storage::disk(MediaDiskResolver::resolve());
        $moved = [];
        $contested = [];
        $failed = [];
        $reclaimed = [];
        $overtaken = [];
        $objectsCopied = 0;

        foreach ($groups as $source => $claimants) {
            // One LIST per prefix rather than four HEADs per connection — and it
            // is also the only honest way to know a promised object is missing,
            // which is the difference between repointing a URL and nulling it.
            try {
                $present = array_map('basename', $disk->files($source));
            } catch (Throwable $e) {
                report($e);
                foreach ($claimants as $claimant) {
                    $failed[] = [$claimant['connection']->getKey(), $source, 'could not list the source prefix'];
                }

                continue;
            }

            $isContested = count($claimants) > 1;
            $groupClean = true;

            foreach ($claimants as $claimant) {
                $connection = $claimant['connection'];
                $target = $claimant['target'];
                $payload = $connection->payload;

                $copied = [];
                $dropped = [];
                $aborted = null;

                foreach (self::MIRROR_FILES as $file => $field) {
                    if (! $this->payloadClaims($payload, $field, $source, $file)) {
                        // Present at source but unpublished = a stale leftover the
                        // seeder's own complement-delete already condemned. Carrying
                        // it forward would move junk into a clean prefix.
                        continue;
                    }
                    if (! in_array($file, $present, true)) {
                        $dropped[] = $file;

                        continue;
                    }
                    if ($dryRun) {
                        $copied[] = $file;
                        $objectsCopied++;

                        continue;
                    }

                    try {
                        $disk->copy("{$source}/{$file}", "{$target}/{$file}");
                    } catch (Throwable $e) {
                        report($e);
                        $aborted = "copy of {$file} threw: ".$e->getMessage();

                        break;
                    }
                    // The copy() return value is not enough on its own: the media
                    // disk can resolve to the `public_dev` alias, which is
                    // throw => false, so a rejected write is a quiet false — the
                    // same trap MediaMirror documents at its own put() calls.
                    if (! $disk->exists("{$target}/{$file}")) {
                        $aborted = "copy of {$file} did not land at the destination";

                        break;
                    }
                    $copied[] = $file;
                    $objectsCopied++;
                }

                if ($aborted !== null) {
                    // Payload untouched, so the source is still the live location
                    // and the group is not reclaimed. The next run repeats this
                    // connection from the top.
                    $failed[] = [$connection->getKey(), $source, $aborted];
                    $groupClean = false;

                    continue;
                }

                if (! $dryRun) {
                    try {
                        // Re-read immediately before the write. The plan was built
                        // minutes ago (a full pass is ~200 rows of network round
                        // trips), and a scheduled refresh re-seeding one of these
                        // connections in the meantime would have written a whole
                        // new payload — which a write from the stale snapshot would
                        // silently discard. Cheap insurance against a lost update
                        // on a row whose whole problem is that two writers shared
                        // one destination.
                        $connection->refresh();
                        $payload = $connection->payload;
                        // Through sourcePrefix(), not a bare `_folder` read: a row
                        // that never got its folder key is located by the URLs it
                        // publishes, and comparing the raw key would treat that
                        // (legitimate) case as a concurrent write and never move it.
                        if ($this->sourcePrefix($connection) !== $source) {
                            // Someone else moved this row while the plan was in
                            // flight. The copies above are harmless duplicates, but
                            // the source is no longer this run's to free on its
                            // behalf — a later pass, reading fresh, will decide.
                            $overtaken[] = [$connection->getKey(), $source];
                            $groupClean = false;

                            continue;
                        }

                        $connection->payload = $this->rewritePayload($payload, $source, $target, $copied, $dropped);
                        // saveQuietly: this is a storage-layout backfill, not a
                        // user edit. IntegrationConnectionObserver::updated() would
                        // dispatch the source delete for us, but saved() would also
                        // re-sync the ingest source for all ~200 rows — so the
                        // reclaim is dispatched explicitly below, once the whole
                        // group is safe to free.
                        $connection->saveQuietly();
                        // saveQuietly skips IntegrationConnectionObserver::saved(),
                        // and with it the edge-cache purge a payload change earns.
                        // Without this the sitepage would keep serving the OLD
                        // profilePicUrl out of cache — pointed at a prefix this run
                        // is about to reclaim, so a stale hit becomes a 404 rather
                        // than merely stale. The refresher is the same object the
                        // observer calls, extracted for exactly this kind of caller.
                        $refresher->refresh($connection);
                    } catch (Throwable $e) {
                        report($e);
                        $failed[] = [$connection->getKey(), $source, 'payload write failed: '.$e->getMessage()];
                        $groupClean = false;

                        continue;
                    }
                }

                $row = [
                    'id' => $connection->getKey(),
                    'username' => is_string($payload['username'] ?? null) ? $payload['username'] : '—',
                    'source' => $source,
                    'target' => $target,
                    'copied' => $copied,
                    'dropped' => $dropped,
                ];
                $moved[] = $row;
                if ($isContested) {
                    $contested[] = $row;
                }
            }

            if ($groupClean && $reclaim && ! $dryRun) {
                DeleteMirroredMediaJob::dispatch($source);
                $reclaimed[] = $source;
            }
        }

        $this->render($moved, $contested, $failed, $refused, $overtaken, $reclaimed, $alreadyCurrent, $neverMirrored, $objectsCopied, $dryRun);

        Log::info('media.repair_instagram_mirror_prefix.complete', [
            'dry_run' => $dryRun,
            'connections_migrated' => count($moved),
            'objects_copied' => $objectsCopied,
            'contested' => count($contested),
            'failed' => count($failed),
            'refused' => count($refused),
            'overtaken' => count($overtaken),
            'prefixes_reclaimed' => count($reclaimed),
            'already_current' => $alreadyCurrent,
            'never_mirrored' => $neverMirrored,
        ]);

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Where this connection's mirror lives TODAY.
     *
     * `payload._folder` is the record of it, but it is only written alongside a
     * successful seed — so a row whose folder key never landed is read back off
     * the URLs it publishes instead, which is the same information the observer
     * would have used. Null means nothing was ever mirrored for this connection.
     */
    private function sourcePrefix(IntegrationConnection $connection): ?string
    {
        $payload = $connection->payload;

        $stored = $payload['_folder'] ?? null;
        if (is_string($stored) && trim($stored, '/ ') !== '') {
            return trim($stored, '/ ');
        }

        foreach (self::MIRROR_FILES as $file => $field) {
            foreach ($this->fieldUrls($payload, $field) as $url) {
                if (preg_match('~('.preg_quote(self::MIRROR_NAMESPACE, '~').'[^/]+)/'.preg_quote($file, '~').'$~', $url, $m) === 1) {
                    return $m[1];
                }
            }
        }

        return null;
    }

    /**
     * Does the payload actually publish `{$source}/{$file}`?
     *
     * Only a published object is worth moving, and only a published object that
     * has gone missing is worth nulling — the two questions the migration turns
     * on are both this one.
     *
     * @param  array<string, mixed>  $payload
     */
    private function payloadClaims(array $payload, string $field, string $source, string $file): bool
    {
        foreach ($this->fieldUrls($payload, $field) as $url) {
            if (str_ends_with($url, "{$source}/{$file}")) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function fieldUrls(array $payload, string $field): array
    {
        $value = $payload[$field] ?? null;

        if ($field === 'images') {
            return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
        }

        return is_string($value) && $value !== '' ? [$value] : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $copied
     * @param  list<string>  $dropped
     * @return array<string, mixed>
     */
    private function rewritePayload(array $payload, string $source, string $target, array $copied, array $dropped): array
    {
        $payload['_folder'] = $target;

        foreach ($copied as $file) {
            $field = self::MIRROR_FILES[$file];
            if ($field === 'images') {
                $payload['images'] = array_map(
                    fn (string $url): string => str_replace("{$source}/", "{$target}/", $url),
                    $this->fieldUrls($payload, 'images'),
                );

                continue;
            }
            $payload[$field] = str_replace("{$source}/", "{$target}/", (string) $payload[$field]);
        }

        foreach ($dropped as $file) {
            // The payload promised an object the source prefix does not hold, so
            // the URL was already dead — and once the source is reclaimed it is
            // dead beyond doubt. Null is the honest value, and it is the value
            // every consumer already handles: the seeder writes exactly this when
            // a mirror drops, and the og:image tiering falls through on null.
            $payload[self::MIRROR_FILES[$file]] = $file === 'photo.jpg' ? [] : null;
        }

        // A poster with no reel is the one combination the seeder never writes —
        // it only mirrors reel-cover.jpg once reel.mp4 is on R2 — so a dropped
        // mp4 takes its cover with it rather than leaving a still frame behind
        // that the skeleton would treat as a playable video.
        if (($payload['videoUrl'] ?? null) === null) {
            $payload['videoPoster'] = null;
        }

        return $payload;
    }

    /**
     * @param  list<array<string, mixed>>  $moved
     * @param  list<array<string, mixed>>  $contested
     * @param  list<array{0: string, 1: string, 2: string}>  $failed
     * @param  list<array{0: string, 1: string}>  $refused
     * @param  list<array{0: string, 1: string}>  $overtaken
     * @param  list<string>  $reclaimed
     */
    private function render(array $moved, array $contested, array $failed, array $refused, array $overtaken, array $reclaimed, int $alreadyCurrent, int $neverMirrored, int $objectsCopied, bool $dryRun): void
    {
        $verb = $dryRun ? 'would move' : 'moved';

        if ($moved !== []) {
            $this->table(
                ['connection', 'username', 'from', 'to', $dryRun ? 'would copy' : 'copied', 'dropped'],
                array_map(fn (array $r): array => [
                    $r['id'],
                    $r['username'],
                    $r['source'],
                    $r['target'],
                    $r['copied'] === [] ? '—' : implode(' ', $r['copied']),
                    $r['dropped'] === [] ? '—' : implode(' ', $r['dropped']),
                ], $moved),
            );
        }

        $this->line(sprintf(
            '%s %d connection(s), %d object(s); %d already current, %d never mirrored.',
            ucfirst($verb),
            count($moved),
            $objectsCopied,
            $alreadyCurrent,
            $neverMirrored,
        ));

        if ($reclaimed !== []) {
            $this->line(sprintf('Dispatched DeleteMirroredMediaJob for %d emptied prefix(es).', count($reclaimed)));
        }

        if ($contested !== []) {
            $this->newLine();
            $this->warn('CONTESTED — these prefixes were shared, so the bytes under them belong to only ONE of the claimants:');
            foreach ($contested as $r) {
                $this->warn(sprintf('  %s (%s) ← %s', $r['id'], $r['username'], $r['source']));
            }
            $this->warn('Each now has its own prefix, so a disconnect can no longer delete a neighbour — but the picture itself');
            $this->warn('is only restored by re-scraping these accounts. Isolation is fixed here; identity is not.');
        }

        if ($overtaken !== []) {
            $this->newLine();
            $this->warn('OVERTAKEN — moved by another writer between the plan and the write; left to the next pass:');
            foreach ($overtaken as [$id, $source]) {
                $this->warn("  {$id} (was {$source})");
            }
        }

        $this->reportRefusals($refused);

        if ($failed !== []) {
            $this->newLine();
            $this->error('FAILED — payload left untouched, source prefix left in place, safe to re-run:');
            foreach ($failed as [$id, $source, $reason]) {
                $this->error("  {$id} ({$source}): {$reason}");
            }
        }
    }

    /**
     * @param  list<array{0: string, 1: string}>  $refused
     */
    private function reportRefusals(array $refused): void
    {
        if ($refused === []) {
            return;
        }

        $this->newLine();
        $this->warn('REFUSED — a stored folder outside the instagram namespace is reported, never copied or reclaimed:');
        foreach ($refused as [$id, $source]) {
            $this->warn("  {$id}: {$source}");
        }
    }
}
