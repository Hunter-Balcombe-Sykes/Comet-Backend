<?php

namespace App\Services\PreAccount;

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
    public static function note(string $buildId, string $stage, string $status, string $label, array $payload = []): void
    {
        try {
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
