<?php

use App\Models\Core\User\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupPreAccountBuildsTable();
    setupPreAccountBuildEventsTable();
});

/**
 * Seeds one user, one pre_account_builds row, and a ledger with THREE
 * stages — identity (plain, landed), media (plain, landed), and platforms
 * (TOKENED, landed) — plus a fourth, workplace, left STARTED with no
 * terminal row so the "still open" path is exercised too.
 *
 * Every timestamp is built off one fixed base instant rather than now(), so
 * the elapsed-seconds assertions below are exact regardless of how fast the
 * test itself runs.
 *
 * @return array{user: User, build_id: string, base: Carbon}
 */
function seedTimingLedger(string $handle): array
{
    $user = User::factory()->create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'account_type' => 'partna',
        'status' => 'active',
    ]);

    $base = now()->startOfSecond();
    $buildId = (string) Str::orderedUuid();
    DB::table('core.pre_account_builds')->insert([
        'id' => $buildId,
        'user_id' => $user->id,
        'source_type' => 'instagram',
        'source_ref' => 'testuser',
        'source_ref_lc' => 'testuser',
        'built_via' => 'signup',
        'build_state' => 'building',
        'created_at' => $base,
        'updated_at' => $base,
    ]);

    $insert = function (string $stage, string $status, int $offsetSeconds, array $payload = []) use ($buildId, $base) {
        DB::table('core.pre_account_build_events')->insert([
            'id' => (string) Str::orderedUuid(),
            'build_id' => $buildId,
            'stage' => $stage,
            'status' => $status,
            'label' => "$stage $status",
            'payload' => json_encode($payload),
            'created_at' => $base->copy()->addSeconds($offsetSeconds),
        ]);
    };

    // identity: started@0, landed@5 -> elapsed 5s, identity_s = 5.
    $insert('identity', 'started', 0);
    $insert('identity', 'landed', 5);

    // media: started@3, landed@9 -> elapsed 6s.
    $insert('media', 'started', 3);
    $insert('media', 'landed', 9);

    // platforms, TOKENED: started@6, landed@14 -> elapsed 8s. This is the
    // only READY_STAGES-eligible pair, so all_ready_s = 14 (closed at 14s
    // after the build's own created_at).
    $insert('platforms', 'started', 6, ['token' => 'ig-scrape-1']);
    $insert('platforms', 'landed', 14, ['token' => 'ig-scrape-1']);

    // workplace: started@8, never answered -> still open.
    $insert('workplace', 'started', 8);

    return ['user' => $user, 'build_id' => $buildId, 'base' => $base];
}

it('prints the timing table and computes the identity/all-ready marks', function () {
    $seed = seedTimingLedger('timinguser');

    $this->artisan('setup:timing', ['user' => $seed['user']->handle])
        ->assertSuccessful()
        ->expectsOutputToContain('Identity landed: 5s')
        ->expectsOutputToContain('All platforms.* ready: 14s')
        // first-open (identity@0) to last-close (platforms@14) = 14s.
        ->expectsOutputToContain('Total (first-open to last-close): 14s');
});

it('resolves the user by id and by primary_email too', function () {
    $seed = seedTimingLedger('resolveuser');
    $seed['user']->forceFill(['primary_email' => 'resolveuser@example.test'])->saveQuietly();

    $this->artisan('setup:timing', ['user' => $seed['user']->id])->assertSuccessful();
    $this->artisan('setup:timing', ['user' => 'resolveuser@example.test'])->assertSuccessful();
});

it('fails clearly for an unknown user', function () {
    $this->artisan('setup:timing', ['user' => 'no-such-user'])->assertFailed();
});

it('appends one JSON line with the tokened pair disambiguated and the still-open stage shown as started/null', function () {
    $seed = seedTimingLedger('jsonuser');
    $path = sys_get_temp_dir().'/setup-timing-test-'.Str::random(8).'.jsonl';

    try {
        $this->artisan('setup:timing', ['user' => $seed['user']->handle, '--json' => $path])->assertSuccessful();

        expect(file_exists($path))->toBeTrue();
        $lines = array_values(array_filter(explode("\n", file_get_contents($path))));
        expect($lines)->toHaveCount(1);

        $line = json_decode($lines[0], true);
        expect($line['user'])->toBe('jsonuser')
            ->and($line['build'])->toBe($seed['build_id'])
            ->and($line['identity_s'])->toBe(5)
            ->and($line['all_ready_s'])->toBe(14)
            ->and($line['stages'])->toHaveKey('platforms#ig-scrape-1')
            ->and($line['stages']['platforms#ig-scrape-1']['status'])->toBe('landed')
            ->and($line['stages']['identity']['status'])->toBe('landed')
            ->and($line['stages']['workplace']['closed'])->toBeNull()
            ->and($line['stages']['workplace']['status'])->toBe('started');
    } finally {
        @unlink($path);
    }
});

it('reports all_ready_s as null when no ready-stage pair has closed yet', function () {
    $user = User::factory()->create([
        'handle' => 'noreadyuser',
        'handle_lc' => 'noreadyuser',
        'account_type' => 'partna',
        'status' => 'active',
    ]);
    $base = now()->startOfSecond();
    $buildId = (string) Str::orderedUuid();
    DB::table('core.pre_account_builds')->insert([
        'id' => $buildId,
        'user_id' => $user->id,
        'source_type' => 'instagram',
        'source_ref' => 'testuser2',
        'source_ref_lc' => 'testuser2',
        'built_via' => 'signup',
        'build_state' => 'building',
        'created_at' => $base,
        'updated_at' => $base,
    ]);
    DB::table('core.pre_account_build_events')->insert([
        'id' => (string) Str::orderedUuid(),
        'build_id' => $buildId,
        'stage' => 'identity',
        'status' => 'started',
        'label' => 'identity started',
        'payload' => json_encode([]),
        'created_at' => $base->copy()->addSeconds(1),
    ]);

    $this->artisan('setup:timing', ['user' => $user->handle])
        ->assertSuccessful()
        ->expectsOutputToContain('All platforms.* ready: n/a')
        ->expectsOutputToContain('Identity landed: n/a');
});
