<?php

// #TEST-10: App\Ingest\Runtime\RunExecutor::isClaimed() decides which
// redactions apply for an unclaimed account's stored ingest doc — a PII gate,
// not incidental plumbing. What leaks if it is wrong, concretely (GBP): a
// reviewer's display name, profile URL, and profile photo persisted durably
// for an account nobody has claimed.
//
// Existing coverage is ADJACENT, not this: tests/Unit/Ingest/ManifestTest.php
// and tests/Feature/Ingest/GoogleBusinessConnectorTest.php already prove
// Manifest::redactionsFor(bool) and the GBP when_unclaimed declaration in
// isolation. What was untested is the MIDDLE — isClaimed()'s
// core.users.status -> bool mapping, wired through RunExecutor into the
// stored document.
//
// An end-to-end test through a REAL connector became possible in slice 0, when
// the billed-effect drivers landed (see PlacesDetailsDriverTest's end-to-end
// case). This file keeps its test-local fake connector on purpose: it is about
// isClaimed()'s core.users.status -> bool mapping, and a fake keeps that
// isolated from any vendor's payload shape.

use App\Ingest\Manifest\Manifest;
use App\Ingest\Manifest\SourceKey;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Manifest\StreamSpec;
use App\Ingest\Message\Record;
use App\Ingest\Runtime\Connector;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;
use App\Ingest\Runtime\RunExecutor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupIngestTables();
});

function claimGateManifest(): Manifest
{
    return new Manifest(
        source: SourceKey::of('claimgate_test'),
        identifierKind: 'test',
        hosts: [],
        streams: [
            'data' => new StreamSpec(name: 'data', target: 'none', profile: SourceProfile::Mirror),
        ],
        redactions: ['reviewer_name', 'nested.reviewer_photo', 'reviews.*.author'],
        redactionScopes: [
            'reviewer_name' => 'when_unclaimed',
            'reviews.*.author' => 'when_unclaimed',
            // Always-scoped: must be stripped regardless of claim state.
            'nested.reviewer_photo' => 'always',
        ],
    );
}

/** @param array<string, mixed> $doc */
function claimGateConnector(array $doc): Connector
{
    return new class($doc) implements Connector
    {
        public function __construct(private array $doc) {}

        public static function manifest(): Manifest
        {
            return claimGateManifest();
        }

        public function pull(Pull $pull, Io $io): iterable
        {
            yield new Record('data', 'k1', $this->doc);
        }
    };
}

/** A fixed fixture doc exercising all three redaction paths at once. */
function claimGateDoc(): array
{
    return [
        'reviewer_name' => 'Jane Reviewer',
        'nested' => ['reviewer_photo' => 'https://photo.example/p.jpg'],
        'reviews' => [
            ['author' => 'Bob Author', 'rating' => 5],
            ['author' => 'Carol Author', 'rating' => 4],
        ],
        'safe_field' => 'always here',
    ];
}

/** @param array<string, mixed> $overrides */
function claimGateSource(array $overrides = []): array
{
    return array_merge([
        'id' => (string) Str::uuid(),
        'identifier' => 'claim-gate-test',
        'scope' => 'all',
        'scope_n' => null,
        'source_key' => 'claimgate_test', // deliberately absent from ProjectorRegistry::MAP
    ], $overrides);
}

/**
 * Looks the stored doc up via THIS source's own stream row, never a bare
 * `where('key', ...)` — a test that runs more than one source/stream in the
 * same run (the always-scope test below does) would otherwise read back
 * whichever row happens to sort first/last, which is not what it means to
 * assert.
 */
function claimGateStoredDocFor(string $sourceId, string $key = 'k1'): array
{
    $streamId = DB::table('ingest.streams')->where('source_id', $sourceId)->where('stream_name', 'data')->value('id');
    $doc = DB::table('ingest.record_versions')->where('stream_id', $streamId)->where('key', $key)->value('doc');

    return json_decode((string) $doc, true);
}

it('redacts when_unclaimed paths for an unclaimed account, leaving safe fields intact', function () {
    $userId = createTenant('claimgate-unclaimed-'.Str::lower(Str::random(6)), ['status' => 'unclaimed'])->id;
    $source = claimGateSource(['user_id' => $userId]);

    app(RunExecutor::class)->execute($source, claimGateConnector(claimGateDoc()), claimGateManifest(), 'manual');

    $stored = claimGateStoredDocFor($source['id']);

    expect(array_key_exists('reviewer_name', $stored))->toBeFalse();
    expect(array_key_exists('reviewer_photo', $stored['nested'] ?? []))->toBeFalse();
    foreach ($stored['reviews'] as $review) {
        expect(array_key_exists('author', $review))->toBeFalse();
        expect($review['rating'])->not->toBeNull();
    }
    expect($stored['safe_field'])->toBe('always here');
});

it('keeps when_unclaimed fields intact for a claimed (active) account — a permanently-redacting gate is also a bug', function () {
    $userId = createTenant('claimgate-active-'.Str::lower(Str::random(6)), ['status' => 'active'])->id;
    $source = claimGateSource(['user_id' => $userId]);

    app(RunExecutor::class)->execute($source, claimGateConnector(claimGateDoc()), claimGateManifest(), 'manual');

    $stored = claimGateStoredDocFor($source['id']);

    expect($stored['reviewer_name'])->toBe('Jane Reviewer');
    expect($stored['reviews'][0]['author'])->toBe('Bob Author');
    expect($stored['reviews'][1]['author'])->toBe('Carol Author');
    expect($stored['safe_field'])->toBe('always here');
});

// The other three statuses in users_status_check (baseline_pilot.sql:1171:
// active | suspended | disabled | pending_deletion | unclaimed). 'unclaimed'
// is the ONLY pre-consent state — a suspended or pending-deletion account was
// claimed by a real person who consented at claim time, so suspending them
// must not retroactively start stripping attribution from their listing.
// Without this case the whole non-'active' half of the enum is unpinned:
// narrowing isClaimed() to `$status === 'active'` leaves every other test in
// this file green while silently changing behaviour for three of five states.
it('treats every claimed status as claimed, not just active', function (string $status) {
    $userId = createTenant('claimgate-'.$status.'-'.Str::lower(Str::random(6)), ['status' => $status])->id;
    $source = claimGateSource(['user_id' => $userId]);

    app(RunExecutor::class)->execute($source, claimGateConnector(claimGateDoc()), claimGateManifest(), 'manual');

    $stored = claimGateStoredDocFor($source['id']);

    expect($stored['reviewer_name'])->toBe('Jane Reviewer');
    expect($stored['reviews'][0]['author'])->toBe('Bob Author');
})->with(['suspended', 'disabled', 'pending_deletion']);

it('fails CLOSED (redacts) when user_id points at a UUID with no core.users row at all', function () {
    $source = claimGateSource(['user_id' => (string) Str::uuid()]);

    app(RunExecutor::class)->execute($source, claimGateConnector(claimGateDoc()), claimGateManifest(), 'manual');

    $stored = claimGateStoredDocFor($source['id']);

    expect(array_key_exists('reviewer_name', $stored))->toBeFalse();
    foreach ($stored['reviews'] as $review) {
        expect(array_key_exists('author', $review))->toBeFalse();
    }
});

it('fails CLOSED (redacts) when the source carries no user_id at all', function () {
    $source = claimGateSource(['user_id' => null]);

    app(RunExecutor::class)->execute($source, claimGateConnector(claimGateDoc()), claimGateManifest(), 'manual');

    $stored = claimGateStoredDocFor($source['id']);

    expect(array_key_exists('reviewer_name', $stored))->toBeFalse();
    foreach ($stored['reviews'] as $review) {
        expect(array_key_exists('author', $review))->toBeFalse();
    }
});

it('strips an always-scoped path under BOTH claim states — guards a refactor making all redaction claim-conditional', function () {
    $unclaimedUserId = createTenant('claimgate-always-unclaimed-'.Str::lower(Str::random(6)), ['status' => 'unclaimed'])->id;
    $activeUserId = createTenant('claimgate-always-active-'.Str::lower(Str::random(6)), ['status' => 'active'])->id;

    $unclaimedSource = claimGateSource(['user_id' => $unclaimedUserId]);
    app(RunExecutor::class)->execute($unclaimedSource, claimGateConnector(claimGateDoc()), claimGateManifest(), 'manual');
    $unclaimedStored = claimGateStoredDocFor($unclaimedSource['id']);

    $activeSource = claimGateSource(['user_id' => $activeUserId]);
    app(RunExecutor::class)->execute($activeSource, claimGateConnector(claimGateDoc()), claimGateManifest(), 'manual');
    $activeStored = claimGateStoredDocFor($activeSource['id']);

    expect(array_key_exists('reviewer_photo', $unclaimedStored['nested'] ?? []))->toBeFalse();
    expect(array_key_exists('reviewer_photo', $activeStored['nested'] ?? []))->toBeFalse();
    // Sanity: the two sources are genuinely distinct claim states, not the
    // same row read back twice.
    expect($activeStored['reviewer_name'])->toBe('Jane Reviewer');
    expect(array_key_exists('reviewer_name', $unclaimedStored))->toBeFalse();
});

it('Pull::$isClaimed defaults to unclaimed (false) — a PII gate must fail closed, not open', function () {
    $pull = new Pull(identifier: 'x', stream: claimGateManifest()->stream('data'));

    expect($pull->isClaimed)->toBeFalse();
});
