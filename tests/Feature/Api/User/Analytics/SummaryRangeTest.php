<?php

// Regression guard: the summary endpoint must accept the dashboard's widest
// range presets. days=365 ("Last Year" — and the client-capped "All Time")
// used to 422 because startOfDay/endOfDay padding pushed diffInDays to 365.9
// past a hard >365 boundary; wide ranges now clamp to 730 days instead.

use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSiteVisitsTable();
    setupLinkClicksTable();
});

function sumUser(string $h): User
{
    return User::create([
        'handle' => $h, 'handle_lc' => strtolower($h), 'display_name' => ucfirst($h),
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

function sumSite(User $user): void
{
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'subdomain' => $user->handle,
        'is_published' => 1,
        'settings' => json_encode([]),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

// NOTE: a happy-path 200 for days=365/3650 can't be pinned here — the summary
// query path uses Postgres ILIKE, which the SQLite test mirror can't prepare
// (known schema-drift class, see Comet-Backend CLAUDE.md "Verify Before
// Done"). The wide-range clamp is verified against the deployed backend via
// the dashboard's Last Year / All Time presets instead.

it('still rejects a genuinely malformed range', function () {
    $user = sumUser('badrange');
    sumSite($user);

    actingAsUser($user)
        ->getJson('/api/analytics?from=2026-06-01&to=2026-01-01')
        ->assertStatus(422);
});
