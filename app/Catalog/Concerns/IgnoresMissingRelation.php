<?php

namespace App\Catalog\Concerns;

use Illuminate\Database\QueryException;
use Throwable;

/**
 * Shared by the two catalog runtime lanes (DetectorSuspensions,
 * UnmatchedDomains), both of which touch tables that DO NOT EXIST on
 * production — prod carries no `catalog` schema at all.
 *
 * Both lanes are best-effort and already swallow their failures, so the only
 * thing separating "the schema isn't deployed here" from "something is
 * genuinely broken" is whether a log line is emitted. Warning on the former
 * would fire on every request forever, for a state CLAUDE.md documents as
 * intended — and a warning nobody can act on is how the ones that matter
 * become invisible.
 */
trait IgnoresMissingRelation
{
    /**
     * Is this "the table isn't there" rather than "the query went wrong"?
     *
     * Matched on the driver's own signal: Postgres raises SQLSTATE 42P01
     * (undefined_table); SQLite has no equivalent code and says so only in the
     * message. Narrow by design — a broader match would swallow the faults the
     * callers deliberately still report.
     */
    private function isMissingRelation(Throwable $e): bool
    {
        if ($e instanceof QueryException && $e->getCode() === '42P01') {
            return true;
        }

        return str_contains($e->getMessage(), 'no such table');
    }
}
