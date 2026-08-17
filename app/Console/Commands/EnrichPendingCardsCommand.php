<?php

namespace App\Console\Commands;

use App\Jobs\Platforms\EnrichLinkCardJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\Payloads\CardPayload;
use Illuminate\Console\Command;

/**
 * One-off + safety net for F4 (overnight 2026-08-18): brand-link cards
 * written before connectBrand dispatched EnrichLinkCardJob sat
 * last_refresh_status='pending' forever with the brand label as their name.
 * Dispatch the enrichment for every live card still pending with no refresh
 * on record. Idempotent (the job is unique per family+resource and re-reads
 * the row); safe to schedule.
 */
class EnrichPendingCardsCommand extends Command
{
    protected $signature = 'platforms:enrich-pending-cards {--older-than=10 : minutes since creation} {--dry-run}';

    protected $description = 'Dispatch EnrichLinkCardJob for live link cards still pending with no refresh on record.';

    public function handle(): int
    {
        $rows = IntegrationConnection::query()
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->where('last_refresh_status', 'pending')
            ->whereNull('last_refreshed_at')
            ->where('created_at', '<', now()->subMinutes((int) $this->option('older-than')))
            ->get();

        $dispatched = 0;
        foreach ($rows as $row) {
            $url = CardPayload::fromArray((array) $row->payload)->url();
            if (! is_string($url) || $url === '') {
                continue;
            }
            $family = (string) ($row->platform ?? explode('.', (string) $row->surface_key)[0]);
            if (! $this->option('dry-run')) {
                EnrichLinkCardJob::dispatch((string) $row->user_id, $family, (string) $row->resource_id, $url, (string) $row->surface_key);
            }
            $dispatched++;
        }
        $this->info(($this->option('dry-run') ? 'would dispatch ' : 'dispatched ')."{$dispatched} of {$rows->count()} pending cards");

        return self::SUCCESS;
    }
}
