<?php

namespace App\Jobs\PreAccount;

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Design\HeadshotAutoSeeder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

// Item 9d (2026-09-01): the headshot seed leaves the build's critical path.
// It reads only our own R2 mirror (HeadshotAutoSeeder does no network fetch)
// and nothing in the first render depends on it — the design singleton's
// arrival re-renders via the normal doc-rebuild lane. Non-fatal exactly as
// it was inline: a failure is reported and the build stays ready.
class SeedHeadshotJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** @var list<int> moot at one attempt; declared for the job-hygiene policy. */
    public array $backoff = [30];

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly string $userId,
        public readonly string $siteId,
    ) {
        // Image-adjacent work rides the images lane, not scraping — it holds
        // no vendor budget and must not queue behind a menu scrape.
        $this->onQueue(config('partna.queues.images', 'images'));
    }

    public function uniqueId(): string
    {
        return $this->userId;
    }

    public function handle(HeadshotAutoSeeder $seeder): void
    {
        $user = User::query()->find($this->userId);
        $site = Site::query()->find($this->siteId);
        if (! $user || ! $site) {
            return;
        }

        try {
            $seeder->seedFromInstagram($user, $site);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function failed(Throwable $e): void
    {
        report($e);
    }
}
