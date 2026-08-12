<?php

namespace App\Services\Profile;

use App\Models\Core\User\User;

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
}
