<?php

namespace App\Site\Documents;

use Illuminate\Support\Facades\DB;

/**
 * The build protocol's bookkeeping (plan §9).
 *
 * Every content write bumps `content_revision`. A builder reads it FIRST,
 * builds, then commits its output only if the revision has not moved — a
 * compare-and-set. Without that, two builders racing would publish in
 * completion order rather than revision order, and the slower one's stale
 * document would win.
 *
 * `bump()` is called from Eloquent observers for modelled tables AND
 * explicitly at every raw-write seam. That honesty matters: "observers
 * everywhere" is not true of this codebase, so the seams are named and a CI
 * check keeps the list current rather than trusting that nobody wrote raw SQL.
 */
class BuildState
{
    /** Signal that a site's content changed and its document is now stale. */
    public static function bump(string $siteId): void
    {
        DB::table('site.site_build_state')->upsert(
            [['site_id' => $siteId, 'content_revision' => 1, 'updated_at' => now()]],
            ['site_id'],
            [
                // Increment DB-side: two writers bumping concurrently must both
                // count, or one change silently never triggers a rebuild. The
                // timestamp comes from PHP because it needs no atomicity — and
                // a raw now() would tie this to Postgres for no benefit.
                'content_revision' => DB::raw('site_build_state.content_revision + 1'),
                'updated_at' => now(),
            ],
        );
    }

    /**
     * Read the revisions, creating the row if this site has never had one.
     *
     * The ensure matters: commit() is a conditional UPDATE, and an UPDATE
     * against a missing row affects zero rows — which would make the FIRST
     * build of every new site report "superseded" forever.
     *
     * @return array{content_revision: int, built_revision: int}
     */
    public static function read(string $siteId): array
    {
        $row = DB::table('site.site_build_state')->where('site_id', $siteId)->first();

        if ($row === null) {
            DB::table('site.site_build_state')->insertOrIgnore([
                'site_id' => $siteId,
                'content_revision' => 0,
                'built_revision' => 0,
                'updated_at' => now(),
            ]);

            return ['content_revision' => 0, 'built_revision' => 0];
        }

        return [
            'content_revision' => (int) $row->content_revision,
            'built_revision' => (int) $row->built_revision,
        ];
    }

    public static function isStale(string $siteId): bool
    {
        $state = self::read($siteId);

        return $state['content_revision'] > $state['built_revision'];
    }

    /**
     * Commit a build IF the revision we built from is still current.
     * Returns false when someone else changed content mid-build — the caller
     * rebuilds rather than publishing something already out of date.
     */
    public static function commit(string $siteId, int $builtFromRevision): bool
    {
        $updated = DB::table('site.site_build_state')
            ->where('site_id', $siteId)
            ->where('content_revision', $builtFromRevision)
            ->update([
                'built_revision' => $builtFromRevision,
                'building_since' => null,
                'last_built_at' => now(),
                'updated_at' => now(),
            ]);

        return $updated === 1;
    }
}
