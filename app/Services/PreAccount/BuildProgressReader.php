<?php

namespace App\Services\PreAccount;

use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\PreAccountBuildEvent;
use Illuminate\Support\Facades\DB;

/**
 * The setup progress ledger's reader (2026-09-02) — one place that decides
 * what "finished" means, for both readers:
 *
 *   - the public build poll (the dashboard's signup feed): `done`, the
 *     events in order, and the media mirror counts;
 *   - the handle progress endpoint (the sitepage's overlay): `done` and the
 *     current stage only — a visitor is not the owner and sees no labels,
 *     no thumbnails.
 *
 * DONE, the simple rule (owner, 2026-09-02, "do it quickly"): the build is
 * ready AND content has landed (the poll's content_filled tier) AND the
 * workplace question has an answer (the enriched tier, or the chain said
 * skipped/failed) — OR the build is older than the ceiling, so a stuck
 * vendor call can never hold a loader on screen. Not "no job left": that
 * needs a pending-job counter threaded through every fan-out, and the
 * tiers already say what a person is waiting for.
 */
final class BuildProgressReader
{
    /** After this, finished regardless — the same clock the preparing page and the mirror TTL run on. */
    public const CEILING_MINUTES = 10;

    /** How long a started stage is owed an answer before it stops blocking done. */
    public const OWED_MINUTES = 4;

    /** How many media assets must be settled (mirrored or failed) before done. */
    public const MEDIA_SETTLED_ENOUGH = 12;

    /** The poll caps its feed; a build's ledger is a dozen rows, not a stream. */
    private const EVENT_CAP = 50;

    /**
     * @return array{done: bool, stage: string|null, events: list<array<string, mixed>>, media: array{mirrored: int, total: int, failed: int}}
     */
    public function forPoll(PreAccountBuild $build): array
    {
        $events = $this->events($build);
        $media = $this->mediaCounts($build);

        return [
            'done' => $this->isDone($build, $events, $media),
            'stage' => $this->currentStage($build, $events),
            'events' => array_map(fn (PreAccountBuildEvent $e) => [
                'id' => (string) $e->id,
                'stage' => $e->stage,
                'status' => $e->status,
                'label' => $e->label,
                'payload' => $e->payload ?? [],
                'at' => $e->created_at->toIso8601String(),
            ], $events),
            'media' => $media,
        ];
    }

    /**
     * @return array{done: bool, stage: string|null}
     */
    public function forSite(PreAccountBuild $build): array
    {
        $events = $this->events($build);

        return [
            'done' => $this->isDone($build, $events, $this->mediaCounts($build)),
            'stage' => $this->currentStage($build, $events),
        ];
    }

    /**
     * Finished = every question the feed asks has an answer: content on the
     * page, the workplace settled (a landing, or the chain said skipped/
     * failed), the bio links routed (Instagram builds — the seeder always
     * says, even "nothing to connect"), and the media saved (every asset
     * mirrored, or none to mirror). The first live run (2026-09-02) said
     * done at +24s with 0 of 45 assets saved and no platforms row yet; the
     * feed had visibly not finished. The ceiling still bounds all of it.
     *
     * @param  list<PreAccountBuildEvent>  $events
     * @param  array{mirrored: int, total: int, failed?: int}  $media
     */
    public function isDone(PreAccountBuild $build, array $events, array $media): bool
    {
        if ($build->build_state === PreAccountBuild::STATE_FAILED) {
            return true;
        }
        if ($build->created_at->lt(now()->subMinutes(self::CEILING_MINUTES))) {
            return true;
        }
        if ($build->build_state !== PreAccountBuild::STATE_READY || $build->content_filled_at === null) {
            return false;
        }

        // EVERY stage that said it STARTED is owed an answer (landed, skipped
        // or failed) before the setup is done (2026-09-02): the menu fetch a
        // delivery platform triggers, the website scan, the store fill, the
        // link-page scan, the workplace chain. Started rows are written at
        // the DISPATCH (observer / auto-sync / enrich), so "done" cannot flip
        // true in the queue gap before the job's first line. A stage nobody
        // started is not owed — a bio with no mentions never starts the
        // workplace chain (get_scissored), and that must not hold the setup
        // to the ceiling.
        $started = [];
        $answered = [];
        $platformsAnswered = $build->source_type !== 'instagram';
        foreach ($events as $event) {
            if ($event->status === PreAccountBuildEvent::STATUS_STARTED) {
                // A stage that started and never answered stops blocking
                // after OWED_MINUTES — a job that died without its failed()
                // note must not hold the setup to the 10-minute ceiling.
                if ($event->created_at->gt(now()->subMinutes(self::OWED_MINUTES))) {
                    $started[$event->stage] = true;
                }
            } else {
                $answered[$event->stage] = true;
            }
            if ($event->stage === PreAccountBuildEvent::STAGE_PLATFORMS) {
                $platformsAnswered = true;
            }
        }
        $workplaceAnswered = $build->enriched_at !== null
            || isset($answered[PreAccountBuildEvent::STAGE_WORKPLACE])
            || ! isset($started[PreAccountBuildEvent::STAGE_WORKPLACE]);
        foreach ($started as $stage => $_) {
            if ($stage !== PreAccountBuildEvent::STAGE_WORKPLACE && ! isset($answered[$stage])) {
                return false;
            }
        }
        // A failed, aged asset is settled too (2026-09-02, teegandyson: 2 of 22
        // dead CDN urls held the setup open to the ceiling).
        // Enough of the media, not ALL of it (2026-09-02): a listing with 67
        // photos held "done" for the whole mirror queue while the pages
        // already rendered from source urls. The first dozen settled — or
        // every one when there are fewer — is the bar; the rest keep copying
        // behind a site that is already whole.
        $settled = $media['mirrored'] + ($media['failed'] ?? 0);
        $mediaSaved = $media['total'] === 0 || $settled >= min($media['total'], self::MEDIA_SETTLED_ENOUGH);

        return $workplaceAnswered && $platformsAnswered && $mediaSaved && ! $this->stillConnecting($build);
    }

    /**
     * "Live" means nothing is still connecting or syncing (owner, batch 3
     * E.1, 2026-09-02 — sammylalor read "live", then products loaded onto
     * /shop minutes later). Two queues the stage ledger could not see: a
     * brand store's connect + first fill (content.storefronts: connect_status
     * still pending, or settled but its products not yet auto-selected) and
     * an ingest source's first pull (needs_eager_run / in flight). Each row
     * is owed only for OWED_MINUTES from its own creation, so a stuck job
     * cannot hold the card to the ceiling.
     */
    private function stillConnecting(PreAccountBuild $build): bool
    {
        if ($build->user_id === null) {
            return false;
        }
        try {
            $since = now()->subMinutes(self::OWED_MINUTES)->toDateTimeString();
            $pg = DB::connection('pgsql');
            $stores = $pg->table('content.storefronts')
                ->where('user_id', $build->user_id)
                ->where('created_at', '>', $since)
                ->where(fn ($q) => $q->where('connect_status', 'pending')->orWhereNull('products_autoselected_at'))
                ->exists();
            if ($stores) {
                return true;
            }

            return $pg->table('ingest.sources')
                ->where('user_id', $build->user_id)
                ->where('created_at', '>', $since)
                ->where(fn ($q) => $q->where('needs_eager_run', true)->orWhereNotNull('in_flight_since'))
                ->exists();
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * The stage a loader would name: the newest started-but-not-landed
     * stage, else the newest landed one, else the build state itself.
     *
     * @param  list<PreAccountBuildEvent>  $events
     */
    public function currentStage(PreAccountBuild $build, array $events): string
    {
        $open = [];
        $latest = null;
        foreach ($events as $event) {
            $latest = $event->stage;
            if ($event->status === PreAccountBuildEvent::STATUS_STARTED) {
                $open[$event->stage] = true;
            } else {
                unset($open[$event->stage]);
            }
        }
        if ($open !== []) {
            return (string) array_key_last($open);
        }
        if ($latest !== null) {
            return $latest;
        }

        return match ($build->build_state) {
            PreAccountBuild::STATE_READY => PreAccountBuildEvent::STAGE_READY,
            PreAccountBuild::STATE_FAILED => PreAccountBuildEvent::STAGE_FAILED,
            default => PreAccountBuildEvent::STAGE_IDENTITY,
        };
    }

    /**
     * @return list<PreAccountBuildEvent>
     */
    private function events(PreAccountBuild $build): array
    {
        // The poll must never 500 because of the ledger — a read failure is an
        // empty feed, reported.
        try {
            return PreAccountBuildEvent::query()
                ->where('build_id', $build->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit(self::EVENT_CAP)
                ->get()
                ->all();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * How much of the pool has been copied to our storage — "Saving your
     * media 14 of 28". Only the MIRROR-ELIGIBLE assets count: a borrowed
     * entry (a Shopify product image, a YouTube thumbnail) is served from
     * its own CDN and is unmirrored forever by design (migration
     * 20260819004000). The first real signup after launch (2026-09-02,
     * 39 owned + 79 Shopify images) counted them and could only finish on
     * the ceiling, "39 of 118" on screen the whole way.
     *
     * `failed` (2026-09-02): eligible, unmirrored, attempted at least once and
     * older than two minutes — the mirror job's own retry budget is spent by
     * then, and a dead CDN url must never hold the setup open.
     *
     * @return array{mirrored: int, total: int, failed: int}
     */
    private function mediaCounts(PreAccountBuild $build): array
    {
        if ($build->user_id === null) {
            return ['mirrored' => 0, 'total' => 0, 'failed' => 0];
        }
        try {
            $cutoff = now()->subMinutes(2)->toDateTimeString();
            $row = DB::connection('pgsql')->table('content.media_assets')
                ->where('user_id', $build->user_id)
                ->where('mirror_eligible', true)
                ->selectRaw('count(*) as total, count(storage_path) as mirrored, sum(case when storage_path is null and mirror_attempts >= 1 and created_at < ? then 1 else 0 end) as failed', [$cutoff])
                ->first();
        } catch (\Throwable $e) {
            report($e);

            return ['mirrored' => 0, 'total' => 0, 'failed' => 0];
        }

        return ['mirrored' => (int) ($row->mirrored ?? 0), 'total' => (int) ($row->total ?? 0), 'failed' => (int) ($row->failed ?? 0)];
    }
}
