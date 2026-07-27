<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
});

function reprojectObservation(string $url, ?string $surfaceKey, string $observedAt = 'now'): void
{
    DB::table('routing.link_observations')->insert([
        'id' => (string) Str::uuid(),
        'observed_at' => $observedAt === 'now' ? now() : $observedAt,
        'source' => 'paste',
        'raw_url' => $url,
        'canonical_url' => $url,
        'surface_key' => $surfaceKey,
        'verdict' => $surfaceKey === null ? 'note' : 'place',
        'evidence' => '{}',
    ]);
}

it('reports nothing to do when the window is empty', function () {
    Artisan::call('routing:reproject', ['--since' => '30d']);

    expect(Artisan::output())->toContain('nothing to replay');
});

it('counts an unchanged classification as unchanged', function () {
    reprojectObservation('https://www.instagram.com/someone', 'instagram.profile');

    Artisan::call('routing:reproject', ['--since' => '30d']);

    expect(Artisan::output())->toContain('0 reclassified, 0 newly matched, 0 lost, 1 unchanged');
});

it('flags a link the current rules now match that they previously did not', function () {
    // Recorded as unmatched; today's catalog knows Apple Music (the full-host
    // key bug is fixed), so this is a NEW match.
    reprojectObservation('https://music.apple.com/au/artist/some-band/12345', null);

    Artisan::call('routing:reproject', ['--since' => '30d']);
    $output = Artisan::output();

    expect($output)->toContain('1 newly matched')
        ->and($output)->toContain('NEWLY MATCHED')
        ->and($output)->toContain('apple_music.artist');
});

it('flags — and fails on — a classification the current rules would lose', function () {
    // Recorded as connected to a surface the rules no longer place for this
    // URL. Losing behaviour users already have is the one bucket that must
    // fail the command, so it can gate a rules PR.
    reprojectObservation('https://joesplumbing.com.au/', 'fresha.book');

    $exit = Artisan::call('routing:reproject', ['--since' => '30d']);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('1 lost')
        ->and($output)->toContain('LOST');
});

it('reports a reclassification when the surface changes', function () {
    reprojectObservation('https://www.instagram.com/someone', 'x.profile');

    Artisan::call('routing:reproject', ['--since' => '30d']);
    $output = Artisan::output();

    expect($output)->toContain('1 reclassified')
        ->and($output)->toContain('x.profile → instagram.profile');
});

it('honours the window', function () {
    reprojectObservation('https://www.instagram.com/someone', 'instagram.profile', now()->subDays(60)->toDateTimeString());

    Artisan::call('routing:reproject', ['--since' => '30d']);

    expect(Artisan::output())->toContain('nothing to replay');
});

it('rejects an unparseable window rather than guessing one', function () {
    $exit = Artisan::call('routing:reproject', ['--since' => 'last tuesday']);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('Could not parse');
});
