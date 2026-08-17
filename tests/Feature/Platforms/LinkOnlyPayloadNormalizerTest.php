<?php

// Phase 1.2 follow-through. The three demoted platforms publish through an
// allowlist of `['username', 'url']`, and the payloads the old scrape lane
// wrote do not all carry `username` — twitch stored `login`, skool and strava
// stored only `url` plus decoration. Without this backfill those rows publish a
// link with a null label.

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Migration\LinkOnlyPayloadNormalizer;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function lopnConnection(string $platform, array $payload): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => createTenant('lopn-'.$platform.'-'.substr(sha1(json_encode($payload)), 0, 6))->id,
        'platform' => $platform,
        'resource_id' => $platform.'-'.substr(sha1(json_encode($payload)), 0, 8),
        'payload' => $payload,
        'is_active' => true,
    ]);
}

function lopnPayload(string $id): array
{
    return json_decode((string) DB::table('site.platform_connections')->where('id', $id)->value('payload'), true);
}

it('derives username from the stored url and drops the unrefreshable decoration', function () {
    // The twitch shape the old scrape lane wrote: login + card fields, no
    // username. All the decoration goes — nothing refreshes it now.
    $c = lopnConnection('twitch', [
        'url' => 'https://www.twitch.tv/Loserfruit',
        'login' => 'loserfruit',
        'name' => 'Loserfruit',
        'image' => 'https://static-cdn.jtvnw.net/a.png',
        'description' => 'Streams.',
    ]);

    $result = app(LinkOnlyPayloadNormalizer::class)->run();

    expect($result['normalized'])->toBe(1)
        ->and(lopnPayload($c->id))->toBe([
            'username' => 'loserfruit',
            'url' => 'https://www.twitch.tv/loserfruit',
        ]);
});

it('derives the username from the url rather than the stored login', function () {
    // The point of reading `url`: the SAME normalizer governs a fresh connect,
    // so a migrated row and a re-typed one land on byte-identical values.
    // Deriving from `login` would be a second implementation of one rule, free
    // to drift. Here the stored login is stale/wrong and the url is right.
    $c = lopnConnection('twitch', [
        'url' => 'https://www.twitch.tv/monstercat',
        'login' => 'STALE_VALUE',
    ]);

    app(LinkOnlyPayloadNormalizer::class)->run();

    expect(lopnPayload($c->id)['username'])->toBe('monstercat');
});

it('normalizes skool and strava from url alone', function () {
    $skool = lopnConnection('skool', ['url' => 'https://www.skool.com/mock-community', 'source' => 'scan']);
    $strava = lopnConnection('strava', ['url' => 'https://www.strava.com/clubs/231407', 'members' => 7081174]);

    app(LinkOnlyPayloadNormalizer::class)->run();

    expect(lopnPayload($skool->id))->toBe(['username' => 'mock-community', 'url' => 'https://www.skool.com/mock-community'])
        ->and(lopnPayload($strava->id))->toBe(['username' => '231407', 'url' => 'https://www.strava.com/clubs/231407']);
});

it('is idempotent — a second run rewrites nothing', function () {
    lopnConnection('twitch', ['url' => 'https://www.twitch.tv/loserfruit', 'login' => 'loserfruit']);

    $first = app(LinkOnlyPayloadNormalizer::class)->run();
    $second = app(LinkOnlyPayloadNormalizer::class)->run();

    expect($first['normalized'])->toBe(1)
        ->and($first['already_normalized'])->toBe(0)
        ->and($second['normalized'])->toBe(0)
        ->and($second['already_normalized'])->toBe(1);
});

it('writes nothing under --dry-run but reports what it would do', function () {
    $c = lopnConnection('twitch', ['url' => 'https://www.twitch.tv/loserfruit', 'login' => 'loserfruit']);

    $result = app(LinkOnlyPayloadNormalizer::class)->run(dryRun: true);

    expect($result['normalized'])->toBe(1)
        ->and(lopnPayload($c->id))->toHaveKey('login');
});

it('reports an unparseable url instead of rewriting it', function () {
    // A stored url that no longer satisfies the rule a fresh connect applies.
    // Rewriting it would be guessing at somebody's link; the row keeps its
    // payload and the run reports a failure so it gets looked at.
    $c = lopnConnection('skool', ['url' => 'https://www.skool.com/signup']);

    $result = app(LinkOnlyPayloadNormalizer::class)->run();

    expect($result['unparseable'])->toBe(1)
        ->and($result['normalized'])->toBe(0)
        ->and(lopnPayload($c->id))->toBe(['url' => 'https://www.skool.com/signup']);
});

it('leaves a payload with no url alone', function () {
    $c = lopnConnection('strava', ['name' => 'A Club']);

    $result = app(LinkOnlyPayloadNormalizer::class)->run();

    expect($result['skipped_no_url'])->toBe(1)
        ->and(lopnPayload($c->id))->toBe(['name' => 'A Club']);
});
