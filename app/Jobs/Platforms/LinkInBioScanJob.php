<?php

namespace App\Jobs\Platforms;

use App\Models\Core\User\PreAccountBuildEvent;
use App\Models\Core\User\User;
use App\Routing\Importers\LinkInBioImporter;
use App\Services\PreAccount\BuildProgress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

// Unrolls a curated link-in-bio page (Linktree/Milkshake/Beacons/Stan Store)
// found in an Instagram bio. Since 2026-08-18 this is a thin queued shell over
// LinkInBioImporter — the P8 successor path (LinkRoutingService: observe →
// project → place → reconcile), the first of the legacy router's nine
// consumers to migrate. Everything the old inline loop did line-by-line now
// lives behind import(): the own-host chrome skip, per-link routing, the
// note→card write, the unknown→commerce-probe budget, the zero-yield bio-URL
// floor, and the conflict notification. Conflict findings surface through the
// intent ledger (state=blocked, block_reason=conflict) folded into
// GET /platforms/instagram/synced at read time by SyncFindingsBridge (B4) —
// the payload syncFindings merge this job used to perform is gone with the
// legacy path.
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

    // VESTIGIAL since the LinkInBioImporter migration: on the new pipeline
    // booking exclusivity is reconciler-owned (SourceReconciler's XOR holds a
    // second venue as a conflict regardless of any caller flag), so this no
    // longer changes behaviour. Kept on the constructor because all four
    // dispatch sites still pass it; remove with them when InstagramAutoSync
    // migrates. Deliberately NOT part of uniqueId() below, as before.
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

    public function handle(LinkInBioImporter $importer): void
    {
        // Setup progress (2026-09-02): say the stage has STARTED, so the
        // signup card's status line names the job actually running.
        BuildProgress::noteForUser($this->userId, PreAccountBuildEvent::STAGE_PLATFORMS, PreAccountBuildEvent::STATUS_STARTED, 'Checking your link page');
        $user = User::find($this->userId);
        if ($user === null) {
            // The STARTED above is already on the ledger — leaving it
            // unanswered pins every platforms.* setup pass at ready:false
            // permanently (SetupPayload::openStages reads the LAST row per
            // stage). Answer it before standing down.
            BuildProgress::noteForUser($this->userId, PreAccountBuildEvent::STAGE_PLATFORMS, PreAccountBuildEvent::STATUS_SKIPPED, "Couldn't read your link page");

            return;
        }

        $result = $importer->import($user, $this->bioPageUrl, 'link_in_bio');

        Log::info('platforms.link_in_bio_scan.completed', [
            'user_id' => $this->userId,
            'bio_page_url' => $this->bioPageUrl,
            ...$result,
        ]);

        // Setup progress (2026-09-02): the link page is where most platforms
        // connect, so the feed's platforms row is written here too.
        $placed = array_values(array_filter((array) ($result['placed_platforms'] ?? []), 'is_string'));
        if (($result['outcome'] ?? null) !== 'ok') {
            // Setup progress (2026-09-02): an owed stage gets its answer.
            BuildProgress::noteForUser($this->userId, PreAccountBuildEvent::STAGE_PLATFORMS, PreAccountBuildEvent::STATUS_SKIPPED, "Couldn't read your link page — add platforms from the dashboard");
        }
        if (($result['outcome'] ?? null) === 'ok') {
            $label = $placed === []
                ? 'Checked your link page — nothing new to connect'
                : 'Connected '.BuildProgress::count(count($placed), 'platform', 'platforms').' from your link page';
            if ((int) ($result['contacts'] ?? 0) > 0) {
                $label .= ' — saved your contact details';
            }
            BuildProgress::noteForUser(
                $this->userId,
                PreAccountBuildEvent::STAGE_PLATFORMS,
                $placed === [] ? PreAccountBuildEvent::STATUS_SKIPPED : PreAccountBuildEvent::STATUS_LANDED,
                $label,
                ['platforms' => BuildProgress::platformEntries($this->userId, $placed)],
            );
        }
    }

    public function failed(Throwable $e): void
    {
        // Setup progress (2026-09-02): an owed stage gets its answer.
        BuildProgress::noteForUser((string) $this->userId, PreAccountBuildEvent::STAGE_PLATFORMS, PreAccountBuildEvent::STATUS_FAILED, "Couldn't read your link page");
        report($e);
        Log::error('platforms.link_in_bio_scan.failed', [
            'user_id' => $this->userId,
            'bio_page_url' => $this->bioPageUrl,
            'error' => $e->getMessage(),
        ]);
    }
}
