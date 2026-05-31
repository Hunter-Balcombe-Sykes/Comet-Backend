<?php

namespace App\Console\Commands;

use App\Models\Core\Site\SmartLink;
use App\Services\SmartLinks\SmartLinkRefresher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Tiered smart-link refresh. Commerce + events drift (price/stock/date) →
 * refreshed every 6h; content (covers/names near-immutable) → weekly. Picks
 * the stalest rows first, re-resolves each, updates the snapshot. The
 * SmartLinkObserver busts the public cache as rows change.
 */
class RefreshSmartLinksCommand extends Command
{
    protected $signature = 'smartlinks:refresh {--limit=200 : Max links to refresh this run} {--throttle-ms=200 : Politeness delay between fetches}';

    protected $description = 'Re-fetch stale smart-link snapshots (commerce every 6h, content weekly).';

    public function handle(SmartLinkRefresher $refresher): int
    {
        $limit = (int) $this->option('limit');
        $throttleMs = (int) $this->option('throttle-ms');

        $commerceCutoff = now()->subHours(6);
        $contentCutoff = now()->subWeek();

        $links = SmartLink::query()
            ->where('is_active', true)
            ->where(function ($q) use ($commerceCutoff, $contentCutoff) {
                $q->where(function ($c) use ($commerceCutoff) {
                    $c->where('family', 'commerce')
                        ->where(fn ($w) => $w->whereNull('last_refreshed_at')->orWhere('last_refreshed_at', '<', $commerceCutoff));
                })->orWhere(function ($c) use ($contentCutoff) {
                    $c->where('family', 'content')
                        ->where(fn ($w) => $w->whereNull('last_refreshed_at')->orWhere('last_refreshed_at', '<', $contentCutoff));
                });
            })
            ->orderByRaw('last_refreshed_at ASC NULLS FIRST')
            ->limit($limit)
            ->get();

        $ok = 0;
        $failed = 0;
        foreach ($links as $link) {
            try {
                $refreshed = $refresher->refresh($link);
                $refreshed->last_refresh_status === 'ok' ? $ok++ : $failed++;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('smartlinks:refresh failed for a link', [
                    'smart_link_id' => $link->id,
                    'message' => $e->getMessage(),
                ]);
            }
            if ($throttleMs > 0) {
                usleep($throttleMs * 1000);
            }
        }

        $this->info("Smart links refreshed: {$ok} ok, {$failed} failed (of {$links->count()} stale).");

        return self::SUCCESS;
    }
}
