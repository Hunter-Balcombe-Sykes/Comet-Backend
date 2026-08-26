<?php

namespace App\Jobs\Platforms;

use App\Models\Core\User\User;
use App\Services\Content\ManualMenuWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The race-proof half of the previous-website guard (plan 3 R2,
 * backend-fixes item 5, 2026-08-27).
 *
 * `CustomLinkSeeder::seedCustom()` skips carding a URL on the owner's OWN
 * previous website — but only when `workplace.previous_website` is already
 * persisted. Two async lanes of one signup race (live incident: link-in-bio
 * unroll carded 7 of the owner's old-site pages at 02:42:57; the Google
 * Business flow wrote previous_website at 02:43:55). Lane ordering cannot be
 * guaranteed, so this sweep makes the guard EVENTUALLY correct regardless of
 * order: when previous_website lands (or changes), scrape-seeded cards whose
 * host matches it are retired.
 *
 * Only cards tagged `link_origin = 'scrape'` are touched — a card a person
 * typed (origin 'manual') and every untagged legacy card survive by
 * construction, mirroring `addManual()`'s exemption.
 */
class SweepPreviousWebsiteCardsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 2;

    public function __construct(
        public readonly string $userId,
        public readonly string $previousWebsite,
    ) {}

    public function handle(): void
    {
        $host = $this->hostOf($this->previousWebsite);
        if ($host === null) {
            return;
        }

        $user = User::find($this->userId);
        if ($user === null) {
            return;
        }

        $rows = DB::connection('pgsql')->table('content.items as i')
            ->join('content.f_link as fl', 'fl.item_id', '=', 'i.id')
            ->join('content.item_tags as t', 't.item_id', '=', 'i.id')
            ->where('i.user_id', $this->userId)
            ->where('i.kind', 'link')
            ->whereNull('i.removed_at')
            ->where('t.tag_type', 'link_origin')
            ->where('t.tag', 'scrape')
            ->distinct()
            ->get(['i.id', 'fl.url']);

        $writer = app(ManualMenuWriter::class);
        $swept = 0;
        foreach ($rows as $row) {
            if ($this->hostOf((string) $row->url) === $host) {
                $writer->markRemoved((string) $row->id);
                $swept++;
            }
        }

        if ($swept > 0) {
            Log::info('platforms.previous_website_cards_swept', [
                'user_id' => $this->userId,
                'host' => $host,
                'swept' => $swept,
            ]);
        }
    }

    /** Lowercased host, leading www. stripped — CustomLinkSeeder's own comparison shape. */
    private function hostOf(string $url): ?string
    {
        $host = parse_url(trim($url), PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        return preg_replace('/^www\./', '', strtolower($host));
    }
}
