<?php

namespace App\Jobs\Design;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\ShopBrand;
use App\Models\Core\User\User;
use App\Services\Design\Presets\Factors\OutsideWebsitesFactor;
use App\Services\Design\WebsiteStyleAnalyzer;
use App\Services\Platforms\Registry\Platform;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

// Fills missing styleAnalysis snapshots for a user's outside-connected
// websites — one per custom link, one per shop brand — feeding the
// OutsideWebsitesFactor's mode vote. Custom-link analyses are a normal
// payload update() so IntegrationConnectionObserver refires: analyses now
// match their URLs, so no re-dispatch (converges), and the resolve job is
// queued by the same observer pass. Shop-brand analyses (FOUND-25) write
// directly to the brand's own site.shop_brands.style_analysis column instead
// — each brand is its own row now, not a shared JSONB map, so there's no
// cross-brand clobber risk and no observer refire needed to converge (the
// dispatching controller explicitly refreshes the cache/presets already).
//
// ShouldBeUniqueUntilProcessing (not plain ShouldBeUnique) per user coalesces
// bursts (e.g. several links added back-to-back) AND lets this job re-dispatch
// itself when the per-run budget runs out (see handle()'s self-continue): the
// "UntilProcessing" variant releases the uniqueness lock the moment the worker
// picks the job up, not after handle() returns — a plain ShouldBeUnique job
// dispatching itself from inside handle() would hit its own still-held lock
// and be silently dropped.
class AnalyzeConnectionWebsitesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [60];

    // BrandScanClient::snapshot() attempts up to 2 waits (networkidle2→load) at
    // partna.brand_scan.timeout (40s) each = 80s worst-case per URL. Bounded by
    // MAX_ANALYSES_PER_RUN=2 → 160s worst-case, kept under this timeout (170),
    // under the scraping supervisor's own limit (180), and under $uniqueFor/360
    // (redis retry_after) — must stay under all three or a slow run + the
    // ShouldBeUniqueUntilProcessing release could let a duplicate through.
    public int $timeout = 170;

    public int $uniqueFor = 360;

    // Lowered from 15: at 80s worst-case per URL, a larger budget risks
    // blowing $timeout. Small batches + the self-continue below still sweep a
    // realistic connection set within a few runs; stragglers are picked up by
    // the next connection write or the backfill command.
    private const MAX_ANALYSES_PER_RUN = 2;

    // Cap on failed()'s own kill-recovery re-dispatch chain (see failed()) so a
    // deterministically-throwing entry can't loop forever — it re-dispatches at
    // most this many times before falling back to the backfill command.
    private const MAX_FAILURE_CONTINUATIONS = 3;

    public function uniqueId(): string
    {
        return $this->userId;
    }

    public function __construct(
        public readonly string $userId,
        // Set only by failed()'s own re-dispatch (see below) to bound the
        // kill-recovery chain; a fresh dispatch (observer/backfill/self-continue)
        // always defaults to 0.
        public readonly int $continuation = 0,
    ) {
        $this->onQueue(config('partna.queues.scraping', 'scraping'));
    }

    /** Does this connection carry an outside-website URL lacking its analysis? */
    public static function connectionNeedsAnalyses(IntegrationConnection $connection): bool
    {
        if (! in_array((string) $connection->platform, OutsideWebsitesFactor::SOURCE_PLATFORMS, true)) {
            return false;
        }

        if ($connection->platform === Platform::Custom->value) {
            $payload = is_array($connection->payload) ? $connection->payload : [];

            return self::entryNeedsAnalysis($payload);
        }

        // shop: brand rows are the source of truth (FOUND-25) — the
        // connection's payload is just the static relational-storage marker.
        // Queried fresh (not the possibly-stale eager-loaded relation) so a
        // caller checking right after a child-row write sees it.
        return $connection->shopBrands()->get()->contains(
            fn (ShopBrand $b) => self::brandNeedsAnalysis($b),
        );
    }

    /** @param array<string, mixed> $entry */
    private static function entryNeedsAnalysis(array $entry): bool
    {
        $url = trim((string) ($entry['url'] ?? ''));
        if ($url === '') {
            return false;
        }
        $analysis = $entry['styleAnalysis'] ?? null;

        // URL mismatch OR analyzer-version mismatch → stale. The version
        // clause makes a VERSION bump re-sweep every stored analysis.
        return ! is_array($analysis)
            || ($analysis['url'] ?? null) !== $url
            || ($analysis['v'] ?? null) !== WebsiteStyleAnalyzer::VERSION;
    }

    /** Does this shop brand carry a URL lacking its current, matching styleAnalysis? */
    private static function brandNeedsAnalysis(ShopBrand $brand): bool
    {
        $url = trim((string) $brand->url);
        if ($url === '') {
            return false;
        }
        $analysis = $brand->style_analysis;

        return ! is_array($analysis)
            || ($analysis['url'] ?? null) !== $url
            || ($analysis['v'] ?? null) !== WebsiteStyleAnalyzer::VERSION;
    }

    public function handle(WebsiteStyleAnalyzer $analyzer): void
    {
        $user = User::query()->find($this->userId);
        if ($user === null) {
            $this->fail(new \RuntimeException("User {$this->userId} not found."));

            return;
        }

        $connections = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->active()
            ->whereIn('platform', OutsideWebsitesFactor::SOURCE_PLATFORMS)
            ->get();

        $budget = self::MAX_ANALYSES_PER_RUN;

        foreach ($connections as $connection) {
            if ($budget <= 0) {
                Log::info('AnalyzeConnectionWebsitesJob: budget exhausted, remainder deferred.', [
                    'user_id' => $this->userId,
                ]);
                break;
            }

            if ($connection->platform === Platform::Custom->value) {
                $payload = is_array($connection->payload) ? $connection->payload : [];
                if (self::entryNeedsAnalysis($payload)) {
                    $payload['styleAnalysis'] = $analyzer->analyze(trim((string) $payload['url']));
                    $connection->update(['payload' => $payload]);
                    $budget--;
                }

                continue;
            }

            // shop: each brand is its own site.shop_brands row (FOUND-25), not a
            // shared JSONB map — analyze each brand missing one, checkpointing
            // after EVERY brand (not once at the end of the connection) so a
            // mid-connection failure — or simply running out of budget partway
            // through a many-brand connection — keeps whatever was already
            // analyzed instead of losing it. No lock/merge needed here (unlike
            // the pre-FOUND-25 shared-payload version): style_analysis is its
            // own column on its own row, so a concurrent brand edit elsewhere
            // can't clobber it and vice versa.
            foreach ($connection->shopBrands()->get() as $brand) {
                if ($budget <= 0) {
                    break;
                }
                if (! self::brandNeedsAnalysis($brand)) {
                    continue;
                }

                // SEM-2: analyze() is a slow HTTP round-trip (up to ~80s) — it
                // must run OUTSIDE any row lock, or a concurrent dashboard save
                // to this connection would block for the full call.
                $analysis = $analyzer->analyze(trim((string) $brand->url));
                $brand->update(['style_analysis' => $analysis]);

                $budget--;
            }
        }

        // Self-continue: budget ran out but connections still need analysis —
        // requeue a delayed follow-up instead of waiting for the next
        // connection write or the backfill command to pick up the remainder.
        $moreToDo = $connections->contains(fn (IntegrationConnection $c) => self::connectionNeedsAnalyses($c));
        if ($budget <= 0 && $moreToDo) {
            self::dispatch($this->userId)->delay(now()->addSeconds(30));
        }
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('design.outside_websites.analyze.failed', [
            'user_id' => $this->userId,
            'error' => $e->getMessage(),
        ]);

        // Kill-recovery: handle()'s own self-continue only fires on a graceful
        // return (budget exhausted, more to do). A hard failure (both $tries
        // exhausted) never reaches that line, so without this the remainder
        // would silently wait for the next connection write or the backfill
        // command. Bounded by MAX_FAILURE_CONTINUATIONS so a deterministically
        // -throwing entry can't loop forever.
        if ($this->continuation >= self::MAX_FAILURE_CONTINUATIONS) {
            return;
        }

        // User gone (deleted/soft-deleted between dispatch and failure) — nothing to sweep.
        if (User::query()->whereKey($this->userId)->doesntExist()) {
            return;
        }

        $moreToDo = IntegrationConnection::query()
            ->where('user_id', $this->userId)
            ->active()
            ->whereIn('platform', OutsideWebsitesFactor::SOURCE_PLATFORMS)
            ->get()
            ->contains(fn (IntegrationConnection $c) => self::connectionNeedsAnalyses($c));

        if ($moreToDo) {
            self::dispatch($this->userId, $this->continuation + 1)->delay(now()->addSeconds(60));
        }
    }
}
