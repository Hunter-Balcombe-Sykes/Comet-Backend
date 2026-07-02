<?php

namespace App\Jobs\Design;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Design\Presets\Factors\OutsideWebsitesFactor;
use App\Services\Design\WebsiteStyleAnalyzer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
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
// observer pass. ShouldBeUnique per user coalesces bursts (e.g. several links
// added back-to-back).
class AnalyzeConnectionWebsitesJob implements ShouldBeUnique, ShouldQueue
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

        return ! is_array($analysis) || ($analysis['url'] ?? null) !== $url;
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

            // shop: brand-keyed map — analyze each brand entry missing one.
            $changed = false;
            foreach ($payload as $key => $brand) {
                if ($budget <= 0) {
                    break;
                }
                if (is_array($brand) && self::entryNeedsAnalysis($brand)) {
                    $payload[$key]['styleAnalysis'] = $analyzer->analyze(trim((string) $brand['url']));
                    $changed = true;
                    $budget--;
                }
            }
            if ($changed) {
                $connection->update(['payload' => $payload]);
            }
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
