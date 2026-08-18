<?php

use App\Jobs\Platforms\CommerceProbeJob;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * R6 (2026-08-18 Instagram wave), second half.
 *
 * ShopProductSeeder now records the product lane and StoreBrandSeeder records
 * both store lanes, which leaves exactly one arm of CommerceProbeJob::probe()
 * reaching no seeder at all: the page that could not be fetched. That link
 * still lands on the user's site as a plain custom link, so "we looked at this
 * and could not read it" is a decision the ledger must carry — otherwise the
 * only links with no observation are the ones that failed silently, which is
 * the same inversion R6 named.
 */
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
    setupRoutingTables();
});

function probeObservationUser(): User
{
    $user = User::factory()->create();
    $site = new Site(['subdomain' => 'probe'.substr((string) $user->id, 0, 8), 'is_published' => true, 'settings' => []]);
    $site->user()->associate($user);
    $site->save();

    return $user->refresh();
}

it('records an unreachable probe as a note, not silence', function () {
    $user = probeObservationUser();
    Http::fake(['*' => Http::response('', 503)]);

    app()->call([new CommerceProbeJob((string) $user->id, 'https://unreachable.example.com/thing'), 'handle']);

    $observation = DB::table('routing.link_observations')->where('user_id', $user->id)->first();

    expect($observation)->not->toBeNull();
    expect($observation->source)->toBe('commerce_probe');
    expect($observation->verdict)->toBe('note');
    expect($observation->block_reason)->toBe('probe_unreachable');
    expect($observation->confidence)->toBeNull();
});
