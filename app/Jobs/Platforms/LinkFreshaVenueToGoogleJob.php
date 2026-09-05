<?php

namespace App\Jobs\Platforms;

use App\Models\Core\User\User;
use App\Services\Platforms\FreshaWorkplaceLinker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Off the connect path: a Fresha connect finished for a partna account, so
 * try to find the venue on Google and add it as the workplace (owner,
 * 2026-08-19). Its own job because the Places search + details are two
 * billed network calls the connect fetch has no budget for; unique per user
 * so a re-scrape doesn't queue it twice; one attempt — a miss is logged and
 * the user can connect Google Business by hand as before.
 */
class LinkFreshaVenueToGoogleJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** @var list<int> */
    public array $backoff = [30];

    public int $timeout = 60;

    public int $uniqueFor = 300;

    /**
     * @param  array<string,mixed>  $venue  FreshaScraper::extractVenue() output
     */
    public function __construct(
        public readonly string $userId,
        public readonly array $venue,
    ) {}

    public function uniqueId(): string
    {
        return $this->userId;
    }

    public function handle(FreshaWorkplaceLinker $linker): void
    {
        $user = User::query()->find($this->userId);
        if ($user === null) {
            return;
        }

        // A.5 originally kept a claimed owner's single-confident match on the
        // OLD auto-connect (attempt()) — "they can disconnect it themselves"
        // — the same "post-claim connect" reasoning that turned out to BE
        // the GlossGenius/Fresha booking auto-connect bug (2026-09-06). An
        // address-match here is the same class of risk: a wrong candidate
        // silently attaches a stranger's Google listing with no accept step,
        // regardless of claim status. Every venue now becomes listing
        // CANDIDATES for the setup dialog / suggestions inbox to ask about —
        // attempt()'s old direct-connect is retired.
        $found = $linker->proposeCandidates($user, $this->venue, 'fresha');
        Log::info('fresha.workplace_link.result', ['user_id' => $this->userId, 'candidates' => $found]);
    }

    /** Terminal: report and log — the user can still connect Google by hand. */
    public function failed(\Throwable $e): void
    {
        report($e);
        Log::warning('fresha.workplace_link.failed', ['user_id' => $this->userId, 'message' => $e->getMessage()]);
    }
}
