<?php

namespace App\Jobs\Platforms;

use App\Models\Core\User\User;
use App\Services\Platforms\FreshaScraper;
use App\Services\Platforms\FreshaWorkplaceLinker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * A.4's Fresha arm: a sign-up build's fresha.book suggestion carries a
 * venue page we can read without connecting anything. Fetch it, extract
 * the venue, and write Google Business listing CANDIDATES (A.5) for the
 * setup dialog's listing pass — at any band, because the venue read costs
 * no connection and the candidates are only ever an offer.
 */
class FreshaListingCandidatesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly string $userId,
        public readonly string $url,
    ) {}

    public function uniqueId(): string
    {
        return $this->userId;
    }

    public function handle(FreshaScraper $scraper, FreshaWorkplaceLinker $linker): void
    {
        $user = User::query()->find($this->userId);
        if ($user === null || ! $user->isUnclaimed()) {
            return;
        }

        $location = $scraper->fetchLocation($this->url);
        $venue = $scraper->extractVenue($location);
        if (trim((string) ($venue['name'] ?? '')) === '') {
            Log::info('fresha.listing_candidates.no_venue', ['user_id' => $this->userId]);

            return;
        }

        $found = $linker->proposeCandidates($user, $venue, 'fresha');
        Log::info('fresha.listing_candidates.result', ['user_id' => $this->userId, 'candidates' => $found]);
    }

    /** Terminal: report and log — the listing pass falls back to Places search. */
    public function failed(\Throwable $e): void
    {
        report($e);
        Log::warning('fresha.listing_candidates.failed', ['user_id' => $this->userId, 'message' => $e->getMessage()]);
    }
}
