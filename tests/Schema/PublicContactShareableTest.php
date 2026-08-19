<?php

// A public contact detail is SHAREABLE — pins that core.users carries no
// uniqueness on public_contact_email / public_contact_number.
//
// The two partial UNIQUE indexes these assertions forbid
// (`users_public_contact_email_unique`, `users_public_contact_number_unique`)
// shipped in the 2026-05-26 baseline and encoded an invariant that is false in
// the real world: two individuals legitimately publish the same shopfront line
// or shared bookings@ inbox. Every automatic writer of these columns sources
// them from exactly such a shared listing —
// WorkplaceObserver::mirrorContactFields mirrors site.workplaces.phone /
// contact_email, and IdentitySync::mirrorPublicContactNumber folds the phone
// off a connected Google Business listing.
//
// Observed on dev 2026-08-19, before 20260819003100/003101 dropped them:
// `ollies` and `fred-sarson` share workplace phone (03) 9132 8966. fred-sarson
// held it; ollies' mirror hit a 23505 that WorkplaceObserver downgrades to a
// Log::warning, so their public page served a stale 0980-550 00 with nothing
// surfaced to the owner. On the typed path (PATCH /api/me) the same collision
// was worse: UpdateUserRequest carries no matching Rule::unique and
// UserSelfController::update wraps a bare fill()+save(), so it surfaced as an
// unhandled 500 rather than a 422.
//
// This lives in the APPLIED-SCHEMA lane deliberately. `composer test` runs
// SQLite, whose stand-in core.users never carried these indexes, so a
// behavioural "two users can share an email" test would pass there whether or
// not the migration was ever applied — vacuous exactly where it matters.
// See CLAUDE.md → "Tests run SQLite, prod is Postgres".
//
// Helpers are file-local, not imported from IndexCoverageTest: Pest hoists
// top-level functions globally and cross-file helpers break under --parallel.

use Illuminate\Support\Facades\DB;
use Tests\SchemaTestCase;

uses(SchemaTestCase::class)->in(__FILE__);

/** Every index currently defined on core.users, by name. */
function usersIndexNames(): array
{
    return array_map(
        static fn ($row) => $row->indexname,
        DB::select("SELECT indexname FROM pg_indexes WHERE schemaname = 'core' AND tablename = 'users'"),
    );
}

dataset('forbiddenPublicContactUniques', [
    'email' => ['users_public_contact_email_unique', 'public_contact_email'],
    'number' => ['users_public_contact_number_unique', 'public_contact_number'],
]);

it('carries no unique index on a public contact column', function (string $index, string $column) {
    // in_array + toBeFalse, NOT expect(...)->not->toContain($index, $message):
    // toContain is VARIADIC, so a trailing "message" is parsed as a second value
    // to search for and the negated assertion passes vacuously. Proven here on
    // 2026-08-19 by re-creating users_public_contact_email_unique in the lane
    // database — the toContain form stayed green against the very index it
    // exists to forbid. toBeFalse() does take a message.
    expect(in_array($index, usersIndexNames(), true))->toBeFalse(
        "core.users.{$column} must not be unique — a shared shopfront number or bookings@ ".
        'inbox is the common case, and the automatic mirrors (WorkplaceObserver, '.
        'IdentitySync) write it from a listing several users can share.'
    );
})->with('forbiddenPublicContactUniques');

// Belt-and-braces on the assertion above: catches a uniqueness constraint
// reintroduced under a DIFFERENT index name, which the name-based check
// would sail straight past.
it('has no unique constraint of any name covering a public contact column', function () {
    $uniques = DB::select(
        "SELECT c.relname AS indexname, a.attname AS column_name
           FROM pg_index i
           JOIN pg_class c ON c.oid = i.indexrelid
           JOIN pg_class t ON t.oid = i.indrelid
           JOIN pg_namespace n ON n.oid = t.relnamespace
           JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY (i.indkey)
          WHERE n.nspname = 'core'
            AND t.relname = 'users'
            AND i.indisunique
            AND a.attname IN ('public_contact_email', 'public_contact_number')"
    );

    expect($uniques)->toBe([], 'A unique index covering a public contact column was reintroduced: '.
        json_encode($uniques));
});

// The dropped UNIQUE index was also serving PublicSignupAvailabilityController's
// `orWhere('public_contact_number', $phone)` equality probe, which runs on every
// signup keystroke. 20260819003102 replaces it with a plain partial btree so the
// constraint drop does not silently regress that probe into a seq scan.
it('keeps the public_contact_number lookup indexed by a non-unique btree', function () {
    $row = DB::selectOne(
        "SELECT i.indisvalid, i.indisunique
           FROM pg_index i
           JOIN pg_class c ON c.oid = i.indexrelid
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = 'core' AND c.relname = 'users_public_contact_number_idx'"
    );

    expect($row)->not->toBeNull('users_public_contact_number_idx is missing — 20260819003102 did not apply.');
    expect((bool) $row->indisvalid)->toBeTrue('Index exists but is INVALID — drop the stub and re-run the migration.');
    expect((bool) $row->indisunique)->toBeFalse('Replacement index must NOT be unique — that is the invariant just dropped.');
});
