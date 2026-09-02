<?php

namespace App\Services\PreAccount;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\PreAccountBuildEvent;

/**
 * The setup progress ledger's one writer (2026-09-02). A producer — a job
 * or service that just landed a piece of a build — calls note() at the
 * same point it logs, so the log line and the event are the same fact and
 * cannot drift. Best-effort by contract: a ledger write must never fail a
 * build, so every failure is reported and swallowed.
 *
 * Most producers know the USER, not the build (they are the same jobs that
 * run on every scheduled refresh for every account). noteForUser() writes
 * only while the user has a live, unclaimed, recent build — after the claim
 * nothing polls the ledger, and an account's Tuesday refresh must not
 * append "Grabbing 12 photos" rows to a build that finished in May.
 */
final class BuildProgress
{
    /** How long after a build's request its producers still write here. */
    private const LIVE_WINDOW_MINUTES = 60;

    /**
     * @param  array<string, mixed>  $payload
     */
    /**
     * Stages that land once per build. A second `landed` row for one of them
     * is a second producer describing the same thing (FreshaWorkplaceLinker
     * and the Google enrich both said "Workplace: STUDIO.MJ" on the
     * jordan.dimitriadis rebuild, 2026-09-02) — the feed keeps the first.
     * Shop and platforms repeat by design (one row per store / per page).
     */
    private const ONE_SHOT_STAGES = [
        PreAccountBuildEvent::STAGE_IDENTITY,
        PreAccountBuildEvent::STAGE_WORKPLACE,
        PreAccountBuildEvent::STAGE_LISTING,
        PreAccountBuildEvent::STAGE_WEBSITE,
    ];

    public static function note(string $buildId, string $stage, string $status, string $label, array $payload = []): void
    {
        try {
            if ($status === PreAccountBuildEvent::STATUS_LANDED
                && in_array($stage, self::ONE_SHOT_STAGES, true)
                && PreAccountBuildEvent::query()->where('build_id', $buildId)->where('stage', $stage)->where('status', $status)->exists()) {
                return;
            }
            $event = new PreAccountBuildEvent;
            $event->forceFill([
                'build_id' => $buildId,
                'stage' => $stage,
                'status' => $status,
                'label' => $label,
                'payload' => $payload,
                'created_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function noteForUser(string $userId, string $stage, string $status, string $label, array $payload = []): void
    {
        try {
            $buildId = PreAccountBuild::query()
                ->where('user_id', $userId)
                ->whereNull('claimed_at')
                ->where('created_at', '>=', now()->subMinutes(self::LIVE_WINDOW_MINUTES))
                ->value('id');
        } catch (\Throwable $e) {
            report($e);

            return;
        }

        if (! is_string($buildId) || $buildId === '') {
            return;
        }

        self::note($buildId, $stage, $status, $label, $payload);
    }

    /**
     * Sign-up preview (2026-09-02, A.5): the platforms row as
     * `{platform, handle, url}` entries, one per slug and in the emitter's
     * order, so the card can show a mark with the handle under it. Both
     * emitters (the bio-link auto-sync and the link-page scan) only know
     * slugs; the handle/url live on the connection rows they just wrote,
     * read here through the payloads' shared `username|handle` / `url|link`
     * keys. A slug with no row (a conflict finding) still gets an entry so
     * the count the label states matches the marks shown.
     *
     * @param  list<string>  $slugs
     * @return list<array{platform: string, handle: string|null, url: string|null}>
     */
    public static function platformEntries(string $userId, array $slugs): array
    {
        $slugs = array_values(array_unique(array_filter($slugs, static fn (string $s): bool => $s !== '')));
        if ($slugs === []) {
            return [];
        }

        $byPlatform = [];
        try {
            $rows = IntegrationConnection::query()
                ->where('user_id', $userId)
                ->whereIn('platform', $slugs)
                ->orderBy('created_at')
                ->get(['platform', 'payload']);
            foreach ($rows as $row) {
                $byPlatform[(string) $row->platform] ??= $row->payload ?? [];
            }
        } catch (\Throwable $e) {
            report($e);
        }

        $str = static fn (mixed $v): ?string => is_string($v) && $v !== '' ? $v : null;

        return array_map(static function (string $slug) use ($byPlatform, $str): array {
            $payload = $byPlatform[$slug] ?? [];

            return [
                'platform' => $slug,
                'handle' => $str($payload['username'] ?? $payload['handle'] ?? null),
                'url' => $str($payload['url'] ?? $payload['link'] ?? null),
            ];
        }, $slugs);
    }

    /**
     * "Landed" copy for a count — "12 photos", "1 reel", "no videos".
     */
    public static function count(int $n, string $singular, string $plural): string
    {
        if ($n === 0) {
            return 'no '.$plural;
        }

        return $n.' '.($n === 1 ? $singular : $plural);
    }
}
