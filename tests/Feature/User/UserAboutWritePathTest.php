<?php

// FOUND-5: tests for the credentials / experience write path through
// PATCH /api/me (UserSelfController::update → SyncUserAboutService).
// Asserts that credentials and experience land in core.user_credentials
// and core.user_experience (not in the legacy about JSONB), and that
// section block is_enabled re-evaluates correctly after each write.

use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();  // also sets up user_credentials + user_experience tables
    setupSitesTable();  // also sets up site.workplaces
    setupBlocksTable();
});

function makeAboutWriteUser(string $handle): User
{
    return User::create([
        'handle' => $handle,
        'handle_lc' => $handle,
        'display_name' => 'Write Path Pro',
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
        'status' => 'active',
    ]);
}

// ── Credentials ──────────────────────────────────────────────────────────────

it('PATCH /me writes credentials to core.user_credentials, not to about JSONB', function () {
    $user = makeAboutWriteUser('cred-write');

    actingAsUser($user)
        ->patchJson('/api/me', [
            'about' => [
                'credentials' => [
                    ['title' => 'Certified Colourist', 'issuer' => 'Redken', 'year' => 2021],
                    ['title' => 'Advanced Cut', 'issuer' => null, 'year' => null],
                ],
            ],
        ])
        ->assertOk();

    // Child table populated.
    $rows = DB::connection('pgsql')->table('core.user_credentials')
        ->where('user_id', $user->id)
        ->orderBy('sort_order')
        ->get();

    expect($rows)->toHaveCount(2);
    expect($rows[0]->title)->toBe('Certified Colourist');
    expect($rows[0]->issuer)->toBe('Redken');
    expect($rows[0]->year)->toBe('2021');
    expect($rows[0]->sort_order)->toBe(0);
    expect($rows[1]->title)->toBe('Advanced Cut');
    expect($rows[1]->sort_order)->toBe(1);

    // Legacy about column does NOT store credentials (stripped before fill).
    $fresh = $user->fresh();
    $about = $fresh->about ?? [];
    expect(array_key_exists('credentials', $about))->toBeFalse();
});

it('PATCH /me replaces existing credentials on the second call (delete-and-reinsert)', function () {
    $user = makeAboutWriteUser('cred-replace');

    actingAsUser($user)
        ->patchJson('/api/me', [
            'about' => ['credentials' => [['title' => 'Old Cert', 'issuer' => 'Old School']]],
        ])
        ->assertOk();

    expect(DB::connection('pgsql')->table('core.user_credentials')->where('user_id', $user->id)->count())->toBe(1);

    // Second patch with a different credential.
    actingAsUser($user)
        ->patchJson('/api/me', [
            'about' => ['credentials' => [['title' => 'New Cert', 'issuer' => 'New School'], ['title' => 'Bonus']]],
        ])
        ->assertOk();

    $rows = DB::connection('pgsql')->table('core.user_credentials')
        ->where('user_id', $user->id)
        ->orderBy('sort_order')
        ->get();

    // Old cert is gone; two new ones remain.
    expect($rows)->toHaveCount(2);
    expect($rows->pluck('title')->all())->toEqual(['New Cert', 'Bonus']);
});

it('PATCH /me flips credentials section is_enabled when first credential is added', function () {
    $user = makeAboutWriteUser('cred-enable');
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $user->id,
        'subdomain' => 'cred-enable',
        'is_published' => 0,
        'settings' => '{}',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    // Credentials block starts disabled — no credentials in the table yet.
    $blockId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => $blockId,
        'user_id' => $user->id,
        'site_id' => $siteId,
        'block_group' => 'sections',
        'block_type' => 'credentials',
        'is_active' => 1,
        'is_enabled' => 0,
        'sort_order' => 0,
        'settings' => '{}',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    actingAsUser($user)
        ->patchJson('/api/me', [
            'about' => ['credentials' => [['title' => 'My Cert']]],
        ])
        ->assertOk();

    // Block is_enabled should now be 1 (reevaluateEnabled ran after insert).
    $block = DB::connection('pgsql')->table('site.blocks')->where('id', $blockId)->first();
    expect((bool) $block->is_enabled)->toBeTrue();
});

// ── Experience ───────────────────────────────────────────────────────────────

it('PATCH /me writes experience to core.user_experience', function () {
    $user = makeAboutWriteUser('exp-write');

    actingAsUser($user)
        ->patchJson('/api/me', [
            'about' => [
                'experience' => [
                    ['role' => 'Senior Stylist', 'place' => 'Salon A', 'start' => '2020-01', 'end' => null, 'description' => 'Led colour team.'],
                ],
            ],
        ])
        ->assertOk();

    $rows = DB::connection('pgsql')->table('core.user_experience')
        ->where('user_id', $user->id)
        ->get();

    expect($rows)->toHaveCount(1);
    expect($rows[0]->role)->toBe('Senior Stylist');
    // Legacy "place" maps to "organisation" column.
    expect($rows[0]->organisation)->toBe('Salon A');
    expect($rows[0]->start_year)->toBe('2020-01');
    expect($rows[0]->end_year)->toBeNull();

    // Legacy about column does NOT store experience.
    $about = $user->fresh()->about ?? [];
    expect(array_key_exists('experience', $about))->toBeFalse();
});

it('PATCH /me omitting credentials key does not clear existing credentials', function () {
    // array_key_exists check: omitting credentials from about should NOT delete
    // what is already in the table (only an explicit credentials:[] should clear).
    $user = makeAboutWriteUser('cred-no-touch');

    DB::connection('pgsql')->table('core.user_credentials')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'title' => 'Existing Cert',
        'sort_order' => 0,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    // PATCH about with only experience — credentials key is absent.
    actingAsUser($user)
        ->patchJson('/api/me', [
            'about' => [
                'experience' => [['role' => 'Stylist']],
            ],
        ])
        ->assertOk();

    // Existing credential must survive.
    expect(DB::connection('pgsql')->table('core.user_credentials')->where('user_id', $user->id)->count())->toBe(1);
});
