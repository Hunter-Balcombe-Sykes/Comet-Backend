<?php

namespace App\Console\Commands;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Content\ManualEventWriter;
use App\Services\Platforms\Payloads\StandaloneEventPayload;
use Illuminate\Console\Command;

/**
 * One-off for R7 (pseudo-platform retirement, 2026-08-19): every remaining
 * `resource_kind='event'` connection row becomes an events-POOL item via
 * ManualEventWriter — the same write the live lanes use now — and the
 * connection row is soft-deleted. Idempotent: the writer updateOrCreates on
 * the canonical URL, and an already-converted row simply refreshes the item.
 */
class ConvertStandaloneEvents extends Command
{
    protected $signature = 'content:convert-standalone-events {--dry-run : Report what would convert, write nothing}';

    protected $description = 'Convert legacy resource_kind=event connection rows into events-pool items, then retire the rows';

    public function handle(ManualEventWriter $writer): int
    {
        $rows = IntegrationConnection::query()
            ->where('resource_kind', 'event')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No standalone-event connection rows remain.');

            return self::SUCCESS;
        }

        $converted = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $payload = StandaloneEventPayload::fromArray($row->payload);
            $event = $payload->event();
            $url = is_string($event['link'] ?? null) && $event['link'] !== ''
                ? $event['link']
                : (is_string($event['url'] ?? null) ? $event['url'] : '');
            $user = User::find($row->user_id);

            if ($user === null || $url === '') {
                $this->warn("SKIP {$row->id} (platform {$row->platform}) — ".($user === null ? 'user gone' : 'no event URL in payload'));
                $skipped++;

                continue;
            }

            $this->line(($this->option('dry-run') ? 'WOULD CONVERT ' : 'CONVERT ')."{$row->id} → pool item for {$url} (user {$user->handle})");

            if ($this->option('dry-run')) {
                continue;
            }

            $item = $writer->addStandalone($user, $url, $event);
            if ($item === null) {
                $this->warn('  pool write refused (no site / cap) — row kept');
                $skipped++;

                continue;
            }

            $row->delete();
            $converted++;
        }

        $this->info("Converted {$converted}, skipped {$skipped}, of {$rows->count()} rows.");

        return self::SUCCESS;
    }
}
