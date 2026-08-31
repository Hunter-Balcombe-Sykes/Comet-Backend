<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\InstagramConnectionSeeder;
use Tests\TestCase;

// Unit tests here do not get Laravel booted by tests/Pest.php (only Feature is
// extended), and IntegrationConnection is an Eloquent model. Opt in, the same way
// tests/Unit/Ingest/SourceProvisionerIdentifierTest.php does.
uses(TestCase::class)->in(__FILE__);

/**
 * The mirror prefix must isolate accounts.
 *
 * Found live 2026-09-01: the prefix was 'platforms/instagram/'.created_at->timestamp,
 * a bare unix second with no account component, so two connections created inside
 * the same second shared it. Two pairs were serving a byte-identical profilePicUrl
 * on dev — aerial-studio/mr-bap under folder 1787835720 and melbourne-acupuncture/
 * the-cobblers-last under 1788085840 — i.e. one account publishing the other's face.
 *
 * Two distinct harms ride on the same line, which is why this is pinned rather than
 * left to the seeder's own tests:
 *   1. The second connection to mirror overwrites the first's profile.jpg.
 *   2. DeleteMirroredMediaJob is dispatched with the payload's folder
 *      (IntegrationConnectionObserver:551 and :638), so disconnecting one account
 *      deletes the other account's mirrored media.
 *
 * A batch build is the condition that produces it — a fleet run creates several
 * connections per second — so the collision rate rose with throughput, which is the
 * opposite of what an identity path should do.
 */
function mirrorFolderFor(IntegrationConnection $connection): string
{
    // The production rule itself, not a restatement of it.
    return InstagramConnectionSeeder::mirrorFolder($connection);
}

it('gives two connections created in the same second different mirror folders', function () {
    $sameSecond = now()->setTime(4, 12, 33);

    $a = new IntegrationConnection(['platform' => 'instagram']);
    $a->id = '01a05770-0000-7000-8000-00000000000a';
    $a->created_at = $sameSecond;

    $b = new IntegrationConnection(['platform' => 'instagram']);
    $b->id = '01a05770-0000-7000-8000-00000000000b';
    $b->created_at = $sameSecond;

    expect($a->created_at->timestamp)->toBe($b->created_at->timestamp)
        ->and(mirrorFolderFor($a))->not->toBe(mirrorFolderFor($b));
});

it('keys the mirror folder on the connection, so a re-mirror lands in the same place', function () {
    $connection = new IntegrationConnection(['platform' => 'instagram']);
    $connection->id = '01a05770-0000-7000-8000-00000000000c';
    $connection->created_at = now();

    $first = mirrorFolderFor($connection);

    // A refresh moves the clock but not the identity.
    $connection->created_at = now()->addHours(3);

    expect(mirrorFolderFor($connection))->toBe($first)
        ->and($first)->toContain('01a05770-0000-7000-8000-00000000000c');
});

it('never derives the folder from a wall-clock second', function () {
    $connection = new IntegrationConnection(['platform' => 'instagram']);
    $connection->id = '01a05770-0000-7000-8000-00000000000d';
    $connection->created_at = now();

    expect(mirrorFolderFor($connection))->not->toContain((string) $connection->created_at->timestamp);
});
