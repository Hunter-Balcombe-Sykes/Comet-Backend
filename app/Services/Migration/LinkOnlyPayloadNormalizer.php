<?php

namespace App\Services\Migration;

use App\Services\Platforms\Normalizers\SkoolNormalizer;
use App\Services\Platforms\Normalizers\StravaNormalizer;
use App\Services\Platforms\Normalizers\TwitchNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 1.2 follow-through: normalise the stored payloads of the three
 * platforms demoted to link-only (twitch, skool, strava) onto the
 * `{username, url}` shape every other link-only platform uses.
 *
 * Why this is needed at all: the public allowlist for these three moved to
 * `['username', 'url']` with the demotion, and the payloads written by the old
 * scrape lane do not all carry `username`. Twitch's rows carry `login`;
 * skool's and strava's carry only `url` plus scrape decoration. Left alone,
 * those rows would publish `username: null` — a link with no label — because
 * PublicIntegrationConnectionResource allowlists keys rather than deriving
 * them.
 *
 * `url` is the input, not `login`, and that is deliberate: the same normalizer
 * that now governs a fresh connect also governs the backfill, so a migrated row
 * and a re-typed one land on byte-identical values. Deriving from `login` would
 * be a second, silently divergent implementation of the same rule.
 *
 * The scrape decoration (`name`, `image`, `description`, `members`, `location`)
 * is DROPPED, not preserved. Nothing refreshes it now the fetch strategies are
 * gone, and an allowlist that no longer names those keys would keep them off
 * the wire anyway — so retaining them would leave rows fatter than their
 * contract, growing staler every day, for no reader.
 *
 * Idempotent: a row already carrying the exact normalized pair is counted
 * `already_normalized` and left untouched, so re-running is free.
 */
class LinkOnlyPayloadNormalizer
{
    /** platform => normalizer. The same instances the registry connects with. */
    private const PLATFORMS = [
        'twitch' => TwitchNormalizer::class,
        'skool' => SkoolNormalizer::class,
        'strava' => StravaNormalizer::class,
    ];

    /** @return array{normalized: int, already_normalized: int, unparseable: int, skipped_no_url: int} */
    public function run(bool $dryRun = false, ?string $platform = null): array
    {
        $result = ['normalized' => 0, 'already_normalized' => 0, 'unparseable' => 0, 'skipped_no_url' => 0];

        $platforms = $platform === null
            ? array_keys(self::PLATFORMS)
            : array_values(array_filter(array_keys(self::PLATFORMS), fn ($p) => $p === $platform));

        foreach ($platforms as $key) {
            $normalizer = app(self::PLATFORMS[$key]);

            $rows = DB::connection('pgsql')->table('site.platform_connections')
                ->where('platform', $key)
                ->whereNull('deleted_at')
                ->get(['id', 'payload']);

            foreach ($rows as $row) {
                $payload = json_decode((string) $row->payload, true);
                $payload = is_array($payload) ? $payload : [];

                $url = is_string($payload['url'] ?? null) ? $payload['url'] : null;
                if ($url === null || trim($url) === '') {
                    // No url means nothing to normalise FROM. Left untouched
                    // rather than guessed at — a wrong link is worse than a
                    // label-less one.
                    $result['skipped_no_url']++;

                    continue;
                }

                $normalized = $normalizer($url);
                if ($normalized === null) {
                    // The stored url no longer parses under the rule a fresh
                    // connect would apply. Reported, never rewritten.
                    $result['unparseable']++;
                    Log::warning('platforms.link_payload_unparseable', [
                        'connection_id' => $row->id, 'platform' => $key, 'url' => $url,
                    ]);

                    continue;
                }

                if (($payload['username'] ?? null) === $normalized['username']
                    && ($payload['url'] ?? null) === $normalized['url']
                    && count($payload) === count($normalized)) {
                    $result['already_normalized']++;

                    continue;
                }

                $result['normalized']++;

                if ($dryRun) {
                    continue;
                }

                DB::connection('pgsql')->table('site.platform_connections')
                    ->where('id', $row->id)
                    ->update([
                        'payload' => json_encode($normalized),
                        'updated_at' => now(),
                    ]);
            }
        }

        return $result;
    }
}
