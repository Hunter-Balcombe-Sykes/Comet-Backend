<?php

namespace App\Console\Commands;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\Payloads\CardPayload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Remove the links-pool cards that "owner ruling 2A" produced for a store the
 * user is ALREADY connected to.
 *
 * The retired rule filed a second ordering / booking / reservations link as a
 * public link card. Where that link is the very one a live connection already
 * publishes as an Order or Book action, the pool card is a duplicate of it —
 * the same destination, twice on the page, one of them unlabelled. Ruling 2A is
 * gone (2026-08-19); this clears what it left behind.
 *
 * Deliberately narrow: ONLY exact URL matches against that user's own live
 * connections. A pooled link for a store the user is not connected to is a link
 * they may well want — `content:enrich-pool-links` gives it its picture instead.
 *
 * Soft delete (`items.removed_at`), the same one the dashboard's delete writes,
 * so a mistake is recoverable and a re-scrape does not resurrect it.
 */
class PruneOverflowLinksCommand extends Command
{
    protected $signature = 'content:prune-overflow-links {--user= : limit to one user id} {--dry-run}';

    protected $description = 'Remove links-pool cards duplicating a URL the user already has connected';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $links = DB::connection('pgsql')->table('content.source_items as si')
            ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
            ->join('content.items as i', 'i.id', '=', 'si.item_id')
            ->join('content.f_link as fl', 'fl.item_id', '=', 'i.id')
            ->where('cs.kind', 'manual')
            ->where('i.kind', 'link')
            ->whereNull('i.removed_at')
            ->whereNotNull('fl.url')
            ->when($this->option('user'), fn ($q, $id) => $q->where('cs.user_id', $id))
            ->distinct()
            ->get(['i.id', 'cs.user_id', 'fl.url']);

        // One query per user, not per link.
        $connectionUrls = [];
        foreach ($links->pluck('user_id')->unique() as $userId) {
            $connectionUrls[(string) $userId] = IntegrationConnection::query()
                ->where('user_id', $userId)
                ->whereNull('deleted_at')
                ->get()
                ->map(fn (IntegrationConnection $c) => $this->normalise((string) (CardPayload::fromArray($c->payload)->url() ?? '')))
                ->filter()
                ->unique()
                ->all();
        }

        $pruned = 0;
        foreach ($links as $link) {
            $url = $this->normalise((string) $link->url);
            if ($url === '' || ! in_array($url, $connectionUrls[(string) $link->user_id] ?? [], true)) {
                continue;
            }

            $this->line(($dryRun ? '[dry-run] would remove ' : 'removed ').$link->url);
            if (! $dryRun) {
                DB::connection('pgsql')->table('content.items')
                    ->where('id', $link->id)
                    ->update(['removed_at' => now(), 'updated_at' => now()]);
            }
            $pruned++;
        }

        $this->line(($dryRun ? '[dry-run] would remove ' : 'removed ').$pruned.' of '.$links->count().' pool link(s)');

        return self::SUCCESS;
    }

    /** Scheme-, case- and trailing-slash-insensitive, so http/https and a stray slash still match. */
    private function normalise(string $url): string
    {
        $url = strtolower(rtrim(trim($url), '/'));

        return (string) preg_replace('#^https?://(www\.)?#', '', $url);
    }
}
