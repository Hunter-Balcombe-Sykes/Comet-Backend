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

/**
 * The pool-lane counterpart of EnrichLinkCardJob (JOB-1).
 *
 * A hand-added link appears immediately titled with its host, then this upgrades
 * the title off-thread once the page has been fetched. Without it, convergence
 * Phase 6's move of custom links onto the pool would have silently downgraded
 * every new link from "the page's real title" to "example.com" — the connection
 * lane had this enrichment and the pool hand-add never did.
 *
 * It upgrades the TITLE and BODY only. favicon/logo are not carried onto the pool
 * at all (LinkPoolWriter's docblock says why), so there is nothing to upgrade.
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
            ->whereNull('i.removed_at')
            ->first(['i.id', 'i.headline_cache']);

        if ($current === null) {
            return; // removed between dispatch and run
        }

        // Only overwrite the HOST FALLBACK. If the headline is anything else the
        // owner either typed it or a previous run already enriched it, and the
        // manual source sits at priority 200 — it would win against every
        // connector headline product-wide, so clobbering here is not recoverable.
        $host = (string) (parse_url($this->url, PHP_URL_HOST) ?: $this->url);
        if ((string) $current->headline_cache !== $host) {
            return;
        }

        $snapshot = $scraper->snapshot($this->url); // slow HTTP; null on failure
        $name = trim((string) ($snapshot['name'] ?? ''));
        if ($name === '' || $name === $host) {
            return;
        }

        $writer->add($user, $this->url, $name, (string) ($snapshot['description'] ?? ''));
    }
}
