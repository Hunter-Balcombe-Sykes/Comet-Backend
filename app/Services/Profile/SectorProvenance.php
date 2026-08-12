<?php

namespace App\Services\Profile;

use App\Models\Core\User\User;
use Illuminate\Support\Facades\Log;

/**
 * Who may overwrite core.users.sector.
 *
 * sector_source used to be read as a permission by three call sites with three
 * different rules, so the FIRST writer won permanently — even a scraper's guess
 * over Google's authoritative answer. This class is the single statement of the
 * law: rank the sources, and let a higher rank correct a lower one.
 */
final class SectorProvenance
{
    public const MANUAL = 'manual';

    public const GOOGLE = 'google-business';

    public const INSTAGRAM = 'instagram';

    /** Mirrors users_sector_source_check, ordered by authority. */
    private const RANKS = [self::INSTAGRAM => 1, self::GOOGLE => 2, self::MANUAL => 3];

    /**
     * May a source refresh a value IT stamped itself? Instagram may not:
     * PARTNA_INSTAGRAM_ACTOR is a no-deploy rollback whose two actors return
     * different keys, so allowing it would let an env flip silently rewrite
     * stored sectors on the next reconnect.
     */
    private const SELF_REFRESH = [self::INSTAGRAM => false, self::GOOGLE => true, self::MANUAL => true];

    public static function mayWrite(User $user, string $incoming): bool
    {
        // Fail closed BEFORE the blank check — an out-of-vocabulary source would
        // otherwise write through on a blank row and hit the CHECK as a 23514,
        // which is unhandled on the Instagram connect path.
        if (! isset(self::RANKS[$incoming])) {
            return false;
        }

        $existingValue = $user->sector;
        if ($existingValue === null || trim($existingValue) === '') {
            return true;
        }

        // A set value with provenance none of the three writers stamped came from
        // a mass-assignment path or a manual data fix. Nothing here outranks it.
        $existingSource = $user->sector_source;
        if (! is_string($existingSource) || ! isset(self::RANKS[$existingSource])) {
            return false;
        }

        $incomingRank = self::RANKS[$incoming];
        $existingRank = self::RANKS[$existingSource];

        return $incomingRank === $existingRank
            ? self::SELF_REFRESH[$incoming]
            : $incomingRank > $existingRank;
    }

    /**
     * Is this write about to move a BUSINESS out of a food sector?
     *
     * Matters because isFood() gates can_use_menu / can_use_reservations /
     * can_use_booking / can_use_online_ordering, and PageCapabilities enforces
     * at WRITE time so pages are not dropped at render — meaning a demotion
     * leaves a Menu page live on the public site while 403-ing the owner out
     * of editing it. Callers pair this with FoodContentProbe (see §4.8).
     *
     * $isBusiness is passed in, never derived: account_type may only be read
     * inside AccountCapabilities. Callers hold the capability boolean already.
     */
    public static function isFoodDemotion(bool $isBusiness, ?string $currentSector, string $incomingSector): bool
    {
        return $isBusiness
            && SectorTaxonomy::isFood($currentSector)
            && ! SectorTaxonomy::isFood($incomingSector);
    }

    /**
     * Record a sector transition, applied or refused.
     *
     * Load-bearing rather than decorative: Instagram may never refresh its own
     * value (see SELF_REFRESH), so when a guess turns out wrong this line is
     * the only record of what wrote it and when. $outcome is required because a
     * refusal logged as a null target is indistinguishable from a clear-to-null.
     *
     * Callers MUST pass the LOCKED row, before the assignment — a pre-lock
     * instance logs stale provenance.
     *
     * LOG_LEVEL is debug on both envs and NIGHTWATCH_LOG_LEVEL is unset, so
     * this reaches Nightwatch as an event, not just a log file.
     */
    public static function logTransition(
        User $user,
        ?string $to,
        ?string $toSource,
        string $caller,
        string $outcome = 'applied',
    ): void {
        Log::info('sector.transition', [
            'user_id' => (string) $user->id,
            'from' => $user->sector,
            'from_source' => $user->sector_source,
            'to' => $to,
            'to_source' => $toSource,
            'caller' => $caller,
            'outcome' => $outcome,
        ]);
    }
}
