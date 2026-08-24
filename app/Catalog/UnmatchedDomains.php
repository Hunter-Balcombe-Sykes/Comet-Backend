<?php

namespace App\Catalog;

use App\Catalog\Concerns\IgnoresMissingRelation;
use App\Routing\Iri;
use App\Routing\LinkObserver;
use App\Routing\Projection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The rot/triage queue: domains the router was asked about and could not
 * place, ranked by how often they turn up.
 *
 * This is the feedback loop that decides which detector gets written next.
 * Without it the catalog only ever grows from someone noticing a gap by hand,
 * and the most-pasted unrecognised platform is indistinguishable from a
 * one-off typo.
 *
 * PRIVACY — the reason this is a purpose-built writer and not a second
 * link_observations insert. This table has NO user_id, so it has no cascade
 * route when an account is deleted (UserCascadeCoverageTest only guards tables
 * that carry one). Anything identifying written here outlives the person it
 * came from. So it stores exactly two things about a URL: the registrable key,
 * which is a brand rather than a person, and a MASKED path shape. Never the
 * raw path, never the query string. routing.link_observations is where the raw
 * URL legitimately lives — it is user-scoped and cascade-deleted.
 *
 * @see LinkObserver the caller, whose writes are best-effort for
 *      the same reason these are
 */
class UnmatchedDomains
{
    use IgnoresMissingRelation;

    private const TABLE = 'catalog.unmatched_domains';

    /**
     * A path segment kept verbatim: letters only, no digits, no separators.
     *
     * Deliberately strict. The looser "looks like a slug" rule you reach for
     * first (`[a-z0-9-]+`) keeps `jane-smith-3f9a2b`, which is the exact thing
     * this masking exists to drop. Static route words — book, schedule,
     * confirm, product — survive it, and those are what tell a triager what
     * kind of surface they are looking at.
     */
    private const STATIC_SEGMENT = '/^[a-z]{1,24}$/';

    /**
     * File one unplaced link, or bump the count on a domain already queued.
     *
     * Best-effort: a diagnostic aggregate must never be the reason a user's
     * link fails to save, and on production the table does not exist at all.
     */
    public function record(Iri $iri, Projection $projection): void
    {
        if ($projection->matched()) {
            return;
        }

        // registrable_key is the primary key; an unroutable input (mailto:, an
        // IP literal, something the suffix list refused) has none to file under.
        $key = $iri->registrableKey;
        if ($key === null || $key === '') {
            return;
        }

        try {
            $this->upsert(
                $key,
                $this->pathShape($iri->path),
                // 'no-rule-matched' means detectors exist for this host and all
                // of them declined — fix a pattern. 'unknown-domain' means
                // nobody has written one — write a detector. Different work,
                // hence the column. Set by LinkProjector; a suspended detector
                // deliberately still counts as a rule that exists.
                $projection->reason === 'no-rule-matched',
            );
        } catch (Throwable $e) {
            // Absence is expected (prod has no catalog schema) and this runs on
            // every unplaced link, so it must not become a per-paste log flood.
            // Any other failure is a real fault and is still reported.
            if (! $this->isMissingRelation($e)) {
                Log::warning('catalog.unmatched_domains.write_failed', [
                    'error' => $e->getMessage(),
                    'registrable_key' => $key,
                ]);
            }
        }
    }

    /**
     * One statement, deliberately.
     *
     * The obvious alternative — UPDATE, and INSERT when it affects no rows —
     * needs an error path for the concurrent-insert race, and a caught unique
     * violation poisons any Postgres transaction the caller happens to be
     * inside. ON CONFLICT has no error path to catch.
     *
     * Laravel's upsert() cannot express `hits = hits + 1` (it assigns the
     * incoming value), and the count is the entire ranking signal, so this is
     * raw. `excluded` and the qualified self-reference are spelled the same
     * way by Postgres and by SQLite 3.24+, which is what the test lane runs.
     *
     * The $hasDetectors bool crossing a raw statement into a Postgres boolean
     * column looks like the classic SQLite-passes-what-Postgres-rejects trap,
     * so it was checked rather than assumed: prepareBindings() casts the bool
     * to int, PDO transmits every parameter as text, and Postgres infers $1 as
     * boolean from the INSERT target and accepts '0'/'1'. Verified against dev
     * 2026-08-19. It is fine as written — do not "fix" it into a string.
     */
    private function upsert(string $key, string $shape, bool $hasDetectors): void
    {
        $now = now();

        DB::connection('pgsql')->statement(
            'INSERT INTO '.self::TABLE.' (registrable_key, sample_path_shape, hits, has_detectors, first_seen_at, last_seen_at)
             VALUES (?, ?, 1, ?, ?, ?)
             ON CONFLICT (registrable_key) DO UPDATE SET
                hits = unmatched_domains.hits + 1,
                last_seen_at = excluded.last_seen_at,
                -- Latest shape wins: the freshest example is the most useful
                -- one to triage against. first_seen_at is deliberately absent —
                -- overwriting it would make every long-standing gap look new.
                sample_path_shape = excluded.sample_path_shape,
                has_detectors = excluded.has_detectors',
            [$key, $shape, $hasDetectors, $now, $now],
        );
    }

    /**
     * `/book/jane-smith-3f9a2b/confirm` → `/book/*​/confirm`.
     *
     * Bounded as well as masked: a bound is what stops a pathological URL
     * becoming the row, and four segments is already more than triage needs.
     */
    private function pathShape(string $path): string
    {
        $segments = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));

        if ($segments === []) {
            return '/';
        }

        $max = max(1, (int) config('partna.catalog.unmatched_path_shape_segments', 4));
        $truncated = count($segments) > $max;

        $shape = array_map(
            fn (string $segment) => preg_match(self::STATIC_SEGMENT, strtolower($segment)) === 1
                ? strtolower($segment)
                : '*',
            array_slice($segments, 0, $max),
        );

        if ($truncated) {
            $shape[] = '…';
        }

        return '/'.implode('/', $shape);
    }
}
