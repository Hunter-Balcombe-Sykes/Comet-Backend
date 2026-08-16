<?php

use App\Exceptions\Platforms\PlatformRefreshBacklogException;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Small, deterministic thresholds so we don't have to seed 500 rows.
    config()->set('partna.refresh.backlog.grace_multiplier', 1);
    config()->set('partna.refresh.backlog.alert_threshold', 1);
});

function backlogUser(): User
{
    return User::create([
        'handle' => 'bk', 'handle_lc' => 'bk', 'display_name' => 'BK', 'first_name' => 'BK',
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => 'bk@example.com',
    ]);
}

it('reports a backlog exception when overdue count exceeds the threshold', function () {
    Exceptions::fake();
    $user = backlogUser();

    foreach (['youtube', 'youtube2'] as $i => $rid) {
        IntegrationConnection::create([
            'user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => $rid,
            'payload' => ['handle' => 'c'], 'last_refreshed_at' => now()->subYear(),
        ]);
    }

    $this->artisan('integrations:refresh-backlog')->assertSuccessful();

    Exceptions::assertReported(PlatformRefreshBacklogException::class);
});

it('does not report when the backlog is within threshold', function () {
    Exceptions::fake();
    backlogUser(); // no overdue connections

    $this->artisan('integrations:refresh-backlog')->assertSuccessful();

    Exceptions::assertNothingReported();
});

// CA-SM review fix (E-5 follow-up): scopeDueForRefresh()'s pending exclusion
// hides a row stranded 'pending' by a dead worker from dueForRefresh()-based
// counts — before that fix, a never-refreshed row (including a stranded
// pending one) fell into the "never refreshed" bucket and WAS counted here.
// Folded back into this SAME alarm (not a new one) so a stranded row still
// trips the existing threshold instead of silently disappearing from it.
it('reports a backlog exception when stranded pending rows alone exceed the threshold', function () {
    Exceptions::fake();
    $user = backlogUser();

    foreach (['youtube', 'youtube2'] as $rid) {
        $stranded = IntegrationConnection::create([
            'user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => $rid,
            'payload' => ['handle' => 'c'], 'last_refresh_status' => 'pending',
        ]);
        IntegrationConnection::query()->where('id', $stranded->id)->update(['updated_at' => now()->subMinutes(10)]);
    }

    $this->artisan('integrations:refresh-backlog')->assertSuccessful();

    Exceptions::assertReported(PlatformRefreshBacklogException::class);
});

// CA-SM review fix: scopeStrandedPending now filters to ->active(), matching
// scopeDueForRefresh. A row deactivated while still 'pending' (e.g. by
// ReconcilePlatformTakedownJob) is never touched again by anything, so
// without this filter it would trip the alarm forever — a permanent false
// positive. Threshold overridden to 0 so a single deactivated row is enough
// to discriminate: without the ->active() filter it would still be counted
// (0 > 0 is false, but 1 > 0 is true), so this fails red on the old scope.
it('does not count a deactivated stranded-pending row toward the backlog', function () {
    Exceptions::fake();
    config()->set('partna.refresh.backlog.alert_threshold', 0);
    $user = backlogUser();

    $deactivated = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => 'youtube-inactive',
        'payload' => ['handle' => 'c'], 'last_refresh_status' => 'pending', 'is_active' => false,
    ]);
    IntegrationConnection::query()->where('id', $deactivated->id)->update(['updated_at' => now()->subMinutes(10)]);

    $this->artisan('integrations:refresh-backlog')->assertSuccessful();

    Exceptions::assertNothingReported();
});

// Sanity companion to the above: an ACTIVE stranded-pending row is still
// counted — the fix is a filter, not an accidental exclusion of everything.
it('still counts an active stranded-pending row toward the backlog', function () {
    Exceptions::fake();
    config()->set('partna.refresh.backlog.alert_threshold', 0);
    $user = backlogUser();

    $active = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => 'youtube-active',
        'payload' => ['handle' => 'c'], 'last_refresh_status' => 'pending', 'is_active' => true,
    ]);
    IntegrationConnection::query()->where('id', $active->id)->update(['updated_at' => now()->subMinutes(10)]);

    $this->artisan('integrations:refresh-backlog')->assertSuccessful();

    Exceptions::assertReported(PlatformRefreshBacklogException::class);
});

it('does not count a fresh in-flight pending row toward the backlog', function () {
    Exceptions::fake();
    $user = backlogUser();

    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => 'youtube',
        'payload' => ['handle' => 'c'], 'last_refresh_status' => 'pending',
    ]);

    $this->artisan('integrations:refresh-backlog')->assertSuccessful();

    Exceptions::assertNothingReported();
});

// Whole-branch review, finding 2: 'custom' (link-card) rows can rest at
// 'pending' indefinitely as a LEGITIMATE final state — CustomLinkSeeder resets
// an existing row to 'pending' without dispatching EnrichLinkCardJob unless
// the row is new, and EnrichLinkCardJob itself deliberately leaves 'pending'
// alone on lock contention rather than force a terminal write. Counting those
// into $overdue would erode the very alarm that now also has to catch a
// stranded refresh row (finding 1).
//
// Convergence Phase 6 INVERTS this case, and the inversion is the point. The
// exclusion existed because a `partna.custom_link` row was never refreshed, so
// 'pending' on one was normal and permanent. Custom links are pool items now
// and that surface cannot be written at all; the rows that carry 'pending'
// today are ordering/booking/reservation CARDS, written pending by
// writeBrandCard() and settled by EnrichLinkCardJob. A pending one past the
// stranded window means that job died — which is precisely the fault this
// alarm exists to see. IntegrationConnection::scopeStrandedPending's
// `platform != 'custom'` arm is now vestigial; slice 7 can drop it.
it('counts a stranded pending link-card row toward the backlog (its enrichment job died)', function () {
    Exceptions::fake();
    config()->set('partna.refresh.backlog.alert_threshold', 0);
    $user = backlogUser();

    $customPending = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'uber_eats.order', 'resource_id' => 'link-abc',
        'payload' => ['kind' => 'link', 'url' => 'https://x.com'], 'last_refresh_status' => 'pending',
    ]);
    IntegrationConnection::query()->where('id', $customPending->id)->update(['updated_at' => now()->subMinutes(10)]);

    $this->artisan('integrations:refresh-backlog')->assertSuccessful();

    Exceptions::assertReported(PlatformRefreshBacklogException::class);
});

// Companion: a genuinely stranded row of a normal platform trips the same
// alarm, so the case above is the rule rather than a special exemption.
it('still counts a genuinely stranded row of a normal (non-custom) platform toward the backlog', function () {
    Exceptions::fake();
    config()->set('partna.refresh.backlog.alert_threshold', 0);
    $user = backlogUser();

    $stranded = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => 'youtube-stranded',
        'payload' => ['handle' => 'c'], 'last_refresh_status' => 'pending',
    ]);
    IntegrationConnection::query()->where('id', $stranded->id)->update(['updated_at' => now()->subMinutes(10)]);

    $this->artisan('integrations:refresh-backlog')->assertSuccessful();

    Exceptions::assertReported(PlatformRefreshBacklogException::class);
});
