<?php

namespace App\Console\Commands;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\MediaPageReader;
use App\Services\Platforms\MediaSeeder;
use Illuminate\Console\Command;

/**
 * One-off for T6b (2026-08-20): the pre-pools "embed any URL" detectors let
 * ITEM links auto-connect as platforms — the live case was a bio's Spotify
 * podcast EPISODE landing as a "Spotify" connection at confidence 99
 * (natalieannehair, 2026-08-19). The detectors are narrowed now; this
 * converts the rows the old ones already placed: every connection whose URL
 * the media grammar claims as an ITEM becomes a watch/listen pool item, and
 * the bogus connection row is soft-deleted (the tombstone then also blocks a
 * re-seed of the same bogus row). Idempotent — the seeder folds on the
 * canonical URL.
 */
class ConvertMediaItemConnections extends Command
{
    protected $signature = 'platforms:convert-media-item-connections {--dry-run : Report what would convert, write nothing}';

    protected $description = 'Convert item-URL platform connections (spotify track/album/episode, soundcloud tracks, …) into pool items, then retire the rows';

    public function handle(MediaSeeder $seeder, MediaPageReader $reader): int
    {
        // Every connection whose payload URL the media grammar claims as an
        // ITEM — platform-agnostic on purpose: the same class of row could
        // exist for soundcloud, vimeo, tidal… wherever the old detectors were
        // looser than the grammar.
        $rows = IntegrationConnection::query()
            ->get()
            ->filter(function (IntegrationConnection $row) use ($reader): bool {
                $url = $this->payloadUrl($row);

                return $url !== null && $reader->classifyItem($url) !== null;
            });

        if ($rows->isEmpty()) {
            $this->info('No item-URL connection rows remain.');

            return self::SUCCESS;
        }

        $converted = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $url = (string) $this->payloadUrl($row);
            $user = User::find($row->user_id);

            if ($user === null) {
                $this->warn("SKIP {$row->id} (platform {$row->platform}) — user gone");
                $skipped++;

                continue;
            }

            $this->line(($this->option('dry-run') ? 'WOULD CONVERT ' : 'CONVERT ')."{$row->id} ({$row->platform}:{$row->resource_id}) → pool item for {$url}");

            if ($this->option('dry-run')) {
                continue;
            }

            // A failed read still retires the bogus row — the item lane can
            // pick the URL up on a future scan; a phantom "platform" cannot.
            $written = $seeder->seedItem($user, $url, origin: 'connect_conversion');
            if ($written === null) {
                $this->warn('  read failed — row retired without an item');
            }

            $row->delete();
            $converted++;
        }

        $this->info("Converted {$converted}, skipped {$skipped}, of {$rows->count()} rows.");

        return self::SUCCESS;
    }

    private function payloadUrl(IntegrationConnection $row): ?string
    {
        // payload is `array<string, mixed>` on the model (NOT NULL, default '{}').
        $url = $row->payload['url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }
}
