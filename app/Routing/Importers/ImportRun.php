<?php

namespace App\Routing\Importers;

use App\Routing\SecretParams;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One-shot acquisitions (plan §3): a previous-website scan, a link-in-bio
 * unroll, an Instagram bio harvest, a menu scan. These are PROCEDURES, not
 * platforms — they emit observations into the router and extractor output
 * into content, and they leave no connection row for the page they scraped.
 *
 * Every run is recorded, because an importer can trigger paid downstream
 * effects and "why did this cost money?" needs an answer. The per-user daily
 * cooldown lives here for the same reason.
 */
class ImportRun
{
    /** Runs of the same kind a user may start per day. */
    private const DAILY_LIMIT = 3;

    public static function start(string $userId, string $kind, ?string $sourceUrl = null): ?string
    {
        if (self::isOnCooldown($userId, $kind)) {
            return null;
        }

        $id = (string) Str::uuid();

        DB::table('routing.import_runs')->insert([
            'id' => $id,
            'user_id' => $userId,
            'kind' => $kind,
            // #PRIV-5: minimiseUrl(), not redactUrl() — source_url is Scope
            // B (routing.import_runs), a non-secret PII carrier. No
            // uniqueness constraint rides on this column's exact text.
            'source_url' => SecretParams::minimiseUrl($sourceUrl),
            'started_at' => now(),
            'created_at' => now(),
        ]);

        return $id;
    }

    /** @param array<string, mixed> $detail */
    public static function finish(
        string $runId,
        string $outcome,
        int $observations = 0,
        int $intents = 0,
        int $items = 0,
        ?string $errorClass = null,
        array $detail = [],
    ): void {
        DB::table('routing.import_runs')->where('id', $runId)->update([
            'finished_at' => now(),
            'outcome' => $outcome,
            'observations_count' => $observations,
            'intents_count' => $intents,
            'items_count' => $items,
            'error_class' => $errorClass,
            'detail' => json_encode($detail),
        ]);
    }

    public static function isOnCooldown(string $userId, string $kind): bool
    {
        return DB::table('routing.import_runs')
            ->where('user_id', $userId)
            ->where('kind', $kind)
            ->where('started_at', '>=', now()->subDay())
            ->count() >= self::DAILY_LIMIT;
    }

    public static function dailyLimit(): int
    {
        return self::DAILY_LIMIT;
    }
}
