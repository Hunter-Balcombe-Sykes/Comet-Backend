<?php

// The NotificationEmailPolicy Eloquent model was removed 2026-09-04: its only
// reference outside its own file was the Gate::policy() registration that
// satisfied PolicyCoverageTest. Both real call sites use the query builder.
// The TABLE is live and must stay.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupNotificationEmailPoliciesTable();
});

// Named as a string, not ::class — an import of a deleted class is what Pint
// and PHPStan would both object to, and the assertion only needs the name.
it('has no NotificationEmailPolicy model', function () {
    expect(class_exists('App\Models\Core\Notifications\NotificationEmailPolicy'))
        ->toBeFalse();
});

// Asserts a VALUE, not ->not->toThrow(Throwable::class). Throwable is an
// interface, so Pest takes toThrow's expected-MESSAGE branch and asserts the
// message contains "Throwable" — which fails whether or not the closure throws,
// and `not` turns both failures into a pass. The version of this test that
// shipped in the first round was that vacuous form AND had no stand-in, so it
// reported green while the query raised "no such table". A count that must equal
// 0 fails loudly on 42P01 instead, which is the entire job of this guard.
it('still has a working notification_email_policies table', function () {
    expect(DB::connection('pgsql')
        ->table('notifications.notification_email_policies')
        ->count())->toBe(0);
});
