<?php

// The NotificationEmailPolicy Eloquent model was removed 2026-09-04: its only
// reference outside its own file was the Gate::policy() registration that
// satisfied PolicyCoverageTest. Both real call sites use the query builder.
// The TABLE is live and must stay.

use Illuminate\Support\Facades\DB;

// Named as a string, not ::class — an import of a deleted class is what Pint
// and PHPStan would both object to, and the assertion only needs the name.
it('has no NotificationEmailPolicy model', function () {
    expect(class_exists('App\Models\Core\Notifications\NotificationEmailPolicy'))
        ->toBeFalse();
});

it('still has a working notification_email_policies table', function () {
    expect(fn () => DB::connection('pgsql')
        ->table('notifications.notification_email_policies')
        ->count())->not->toThrow(Throwable::class);
});
