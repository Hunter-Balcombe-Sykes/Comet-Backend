<?php

namespace App\Jobs\Design;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Design\Presets\Factors\OutsideWebsitesFactor;
use App\Services\Design\WebsiteStyleAnalyzer;
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
// OutsideWebsitesFactor's mode vote. Each payload write is a normal update()
// so IntegrationConnectionObserver refires: analyses now match their URLs, so
// no re-dispatch (converges), and the resolve job is queued by the same
// observer pass.
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

    // Up to MAX_ANALYSES_PER_RUN sites × (page + stylesheets).
    public int $timeout = 300;

    public int $uniqueFor = 360;

    // MAX_LINKS is 20 + a handful of shop brands; 15 covers a realistic set in
    // one run. Rare stragglers are picked up by the next connection write or
    // the backfill command.
    private const MAX_ANALYSES_PER_RUN = 15;

    public function uniqueId(): string
    {
        return $this->userId;
    }

    public function __construct(public readonly string $userId) {}

    /** Does this connection carry an outside-website URL lacking its analysis? */
    public static function connectionNeedsAnalyses(IntegrationConnection $connection): bool
    {
        if (! in_array((string) $connection->platform, OutsideWebsitesFactor::SOURCE_PLATFORMS, true)) {
            return false;
        }
        $payload = is_array($connection->payload) ? $connection->payload : [];

        if ($connection->platform === 'custom') {
            return self::entryNeedsAnalysis($payload);
        }

        foreach ($payload as $brand) {
            if (is_array($brand) && self::entryNeedsAnalysis($brand)) {
                return true;
            }
        }

        return false;
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

    public function handle(WebsiteStyleAnalyzer $analyzer): void
    {
        $user = User::query()->find($this->userId);
        if ($user === null) {
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

            $payload = is_array($connection->payload) ? $connection->payload : [];

            if ($connection->platform === 'custom') {
                if (self::entryNeedsAnalysis($payload)) {
                    $payload['styleAnalysis'] = $analyzer->analyze(trim((string) $payload['url']));
                    $connection->update(['payload' => $payload]);
                    $budget--;
                }

                continue;
            }

            // shop: brand-keyed map — analyze each brand entry missing one,
            // checkpointing after EVERY brand (not once at the end of the
            // connection) so a mid-connection failure — or simply running out
            // of budget partway through a many-brand connection — keeps
            // whatever was already analyzed instead of losing it.
            foreach ($payload as $key => $brand) {
                if ($budget <= 0) {
                    break;
                }
                if (is_array($brand) && self::entryNeedsAnalysis($brand)) {
                    $payload[$key]['styleAnalysis'] = $analyzer->analyze(trim((string) $brand['url']));
                    $connection->update(['payload' => $payload]);
                    $budget--;
                }
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
    }
}
