<?php

namespace App\Jobs\Content;

use App\Models\Core\User\User;
use App\Services\Content\LinkPoolWriter;
use App\Services\Platforms\LinkCardScraper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The pool-lane counterpart of EnrichLinkCardJob (JOB-1).
 *
 * A hand-added link appears immediately titled with its host, then this upgrades
 * the title off-thread once the page has been fetched. Without it, convergence
 * Phase 6's move of custom links onto the pool would have silently downgraded
 * every new link from "the page's real title" to "example.com" — the connection
 * lane had this enrichment and the pool hand-add never did.
 *
 * It upgrades the TITLE (only off the host fallback), the BODY (only when
 * empty) and, since 2026-08-17, the IMAGES — the page's share image and
 * favicon — which LinkPoolWriter carries onto the pool as media. Images are
 * written whenever the snapshot has them: nothing the owner typed lives there.
 *
 * A failed snapshot is a fine final state: the host-titled item is already usable,
 * which is the same posture EnrichLinkCardJob takes.
 */
class EnrichPoolLinkJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(
        public string $userId,
        public string $url,
    ) {
        $this->onQueue(config('partna.queues.scraping'));
    }

    public function uniqueId(): string
    {
        return 'pool-link:'.$this->userId.':'.sha1(strtolower(trim($this->url)));
    }

    public function handle(LinkCardScraper $scraper, LinkPoolWriter $writer): void
    {
        $user = User::query()->find($this->userId);
        if ($user === null) {
            return;
        }

        $coord = LinkPoolWriter::coordFor($this->url);

        $current = DB::connection('pgsql')->table('content.source_items as si')
            ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
            ->join('content.items as i', 'i.id', '=', 'si.item_id')
            ->where('cs.user_id', $this->userId)
            ->where('cs.kind', 'manual')
            ->where('si.coord', $coord)
            ->leftJoin('content.f_text as ft', 'ft.item_id', '=', 'i.id')
            ->whereNull('i.removed_at')
            ->first(['i.id', 'i.headline_cache', 'ft.body']);

        if ($current === null) {
            return; // removed between dispatch and run
        }

        $snapshot = $scraper->snapshot($this->url); // slow HTTP; null on failure
        if ($snapshot === null) {
            return;
        }

        // Only overwrite the HOST FALLBACK. If the headline is anything else the
        // owner either typed it or a previous run already enriched it, and the
        // manual source sits at priority 200 — it would win against every
        // connector headline product-wide, so clobbering here is not recoverable.
        // Same posture for the body: fill an empty one, never replace a typed one.
        // (`add()` keeps the stored headline when handed '' and omits an empty
        // body rather than clearing it, so '' here means "leave it".)
        $host = (string) (parse_url($this->url, PHP_URL_HOST) ?: $this->url);
        $name = trim((string) ($snapshot['name'] ?? ''));
        $titleUpgrade = (string) $current->headline_cache === $host && $name !== '' && $name !== $host
            ? $name
            : '';
        $bodyUpgrade = trim((string) ($current->body ?? '')) === ''
            ? (string) ($snapshot['description'] ?? '')
            : '';
        $favicon = (string) ($snapshot['favicon'] ?? '');
        $logo = (string) ($snapshot['logo'] ?? '');

        if ($titleUpgrade === '' && $bodyUpgrade === '' && $favicon === '' && $logo === '') {
            return; // nothing the page can add
        }

        // enrich: false — this IS the enrichment. LinkPoolWriter::add() now
        // dispatches this job whenever a write brings no images and no body
        // (2026-08-19), and a page that yields only a title upgrade would
        // otherwise re-dispatch the job that just ran, on a loop.
        $writer->add($user, $this->url, $titleUpgrade, $bodyUpgrade, $favicon, $logo, enrich: false);
    }

    /**
     * A permanently failed enrichment is not a user-facing failure: the item is
     * already in the pool, titled with its host, and usable. Recorded so a run of
     * these shows up rather than being swallowed — the same posture
     * EnrichLinkCardJob takes on the connection lane.
     */
    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('content.enrich_pool_link.failed', [
            'user_id' => $this->userId,
            'url' => $this->url,
            'error' => $e->getMessage(),
        ]);
    }
}
