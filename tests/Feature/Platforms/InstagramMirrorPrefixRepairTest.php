<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Jobs\Platforms\DeleteMirroredMediaJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\InstagramConnectionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The E13 backfill (`media:repair-instagram-mirror-prefix`).
 *
 * `4feced1b6` stopped NEW collisions by keying the mirror prefix on the
 * connection uuid, but every row written before it still points at
 * 'platforms/instagram/'.created_at->timestamp. Two pairs were live on dev with
 * one prefix between two people — aerial-studio/mr-bap under 1787835720 and
 * melbourne-acupuncture/the-cobblers-last under 1788085840 — so a shared prefix
 * is the case these tests are built around, not an edge one.
 */
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    config(['partna.media_disk' => 'media']);
    Storage::fake('media');
});

function mirrorRepairUser(string $handle): User
{
    return User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);
}

/**
 * A row exactly as the pre-fix seeder left it: the legacy folder in `_folder`,
 * and every published URL under that same folder.
 */
function legacyMirroredConnection(string $handle, string $token, array $files = ['profile.jpg', 'photo.jpg']): IntegrationConnection
{
    $folder = "platforms/instagram/{$token}";
    $base = 'https://media.test/'.$folder;

    return IntegrationConnection::create([
        'user_id' => mirrorRepairUser($handle)->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => [
            'username' => $handle,
            'mode' => 'automatic',
            '_folder' => $folder,
            'profilePicUrl' => in_array('profile.jpg', $files, true) ? "{$base}/profile.jpg" : null,
            'images' => in_array('photo.jpg', $files, true) ? ["{$base}/photo.jpg"] : [],
            'videoUrl' => in_array('reel.mp4', $files, true) ? "{$base}/reel.mp4" : null,
            'videoPoster' => in_array('reel-cover.jpg', $files, true) ? "{$base}/reel-cover.jpg" : null,
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);
}

function putMirrorObjects(string $token, array $files): void
{
    foreach ($files as $file) {
        Storage::disk('media')->put("platforms/instagram/{$token}/{$file}", "bytes-of-{$token}-{$file}");
    }
}

it('moves a legacy mirror onto the per-connection prefix and repoints the payload', function () {
    $connection = legacyMirroredConnection('solo-act', '1787835720');
    putMirrorObjects('1787835720', ['profile.jpg', 'photo.jpg']);

    $this->artisan('media:repair-instagram-mirror-prefix')->assertSuccessful();

    $target = InstagramConnectionSeeder::mirrorFolder($connection);
    $payload = $connection->fresh()->payload;

    expect(Storage::disk('media')->exists("{$target}/profile.jpg"))->toBeTrue()
        ->and(Storage::disk('media')->exists("{$target}/photo.jpg"))->toBeTrue()
        ->and($payload['_folder'])->toBe($target)
        ->and($payload['profilePicUrl'])->toBe("https://media.test/{$target}/profile.jpg")
        ->and($payload['images'][0])->toBe("https://media.test/{$target}/photo.jpg")
        ->and($payload['profilePicUrl'])->not->toContain('1787835720');
});

it('gives each claimant of a shared prefix its own isolated copy', function () {
    // The aerial-studio / mr-bap pair: same wall-clock second, one folder.
    $a = legacyMirroredConnection('aerial-studio', '1787835720');
    $b = legacyMirroredConnection('mr-bap', '1787835720');
    putMirrorObjects('1787835720', ['profile.jpg', 'photo.jpg']);

    $this->artisan('media:repair-instagram-mirror-prefix')->assertSuccessful();

    $folderA = InstagramConnectionSeeder::mirrorFolder($a);
    $folderB = InstagramConnectionSeeder::mirrorFolder($b);

    expect($folderA)->not->toBe($folderB)
        ->and(Storage::disk('media')->exists("{$folderA}/profile.jpg"))->toBeTrue()
        ->and(Storage::disk('media')->exists("{$folderB}/profile.jpg"))->toBeTrue()
        ->and($a->fresh()->payload['profilePicUrl'])->not->toBe($b->fresh()->payload['profilePicUrl'])
        ->and($a->fresh()->payload['_folder'])->toBe($folderA)
        ->and($b->fresh()->payload['_folder'])->toBe($folderB);
});

it('does not free a shared prefix until every claimant has copied off it', function () {
    // The melbourne-acupuncture / the-cobblers-last pair. The queue is NOT faked
    // here on purpose: on the sync connection the reclaim really deletes, so the
    // ordering rule is observable in the outcome rather than in a dispatch count.
    // Free the prefix after the first claimant and the second finds nothing left
    // to copy — which is the whole reason the reclaim waits for the group.
    //
    // A count assertion could not pin this: DeleteMirroredMediaJob is
    // ShouldBeUnique, so a second dispatch of the same folder is swallowed by the
    // unique lock and a per-claimant dispatch still reads as exactly one push.
    $a = legacyMirroredConnection('melbourne-acupuncture', '1788085840');
    $b = legacyMirroredConnection('the-cobblers-last', '1788085840');
    putMirrorObjects('1788085840', ['profile.jpg']);

    $this->artisan('media:repair-instagram-mirror-prefix')->assertSuccessful();

    $folderA = InstagramConnectionSeeder::mirrorFolder($a);
    $folderB = InstagramConnectionSeeder::mirrorFolder($b);

    expect(Storage::disk('media')->exists("{$folderA}/profile.jpg"))->toBeTrue()
        ->and(Storage::disk('media')->exists("{$folderB}/profile.jpg"))->toBeTrue()
        ->and(Storage::disk('media')->exists('platforms/instagram/1788085840/profile.jpg'))->toBeFalse();
});

it('dispatches the reclaim for the emptied prefix, and skips it under --no-reclaim', function () {
    Queue::fake();
    legacyMirroredConnection('reclaimed', '1788085840');
    putMirrorObjects('1788085840', ['profile.jpg']);

    $this->artisan('media:repair-instagram-mirror-prefix')->assertSuccessful();

    Queue::assertPushed(
        DeleteMirroredMediaJob::class,
        fn (DeleteMirroredMediaJob $job): bool => $job->folder === 'platforms/instagram/1788085840',
    );
});

it('leaves the source prefix in place under --no-reclaim', function () {
    legacyMirroredConnection('kept', '1788085840');
    putMirrorObjects('1788085840', ['profile.jpg']);

    $this->artisan('media:repair-instagram-mirror-prefix', ['--no-reclaim' => true])->assertSuccessful();

    expect(Storage::disk('media')->exists('platforms/instagram/1788085840/profile.jpg'))->toBeTrue();
});

it('is a no-op on a second pass', function () {
    legacyMirroredConnection('twice-over', '1787835720');
    putMirrorObjects('1787835720', ['profile.jpg']);

    $this->artisan('media:repair-instagram-mirror-prefix')->assertSuccessful();
    Queue::fake();

    $this->artisan('media:repair-instagram-mirror-prefix')
        ->expectsOutputToContain('already on its per-connection prefix')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

it('copies nothing and writes nothing on a dry run', function () {
    Queue::fake();
    $connection = legacyMirroredConnection('rehearsal', '1787835720');
    putMirrorObjects('1787835720', ['profile.jpg']);

    $this->artisan('media:repair-instagram-mirror-prefix', ['--dry-run' => true])->assertSuccessful();

    $target = InstagramConnectionSeeder::mirrorFolder($connection);

    expect(Storage::disk('media')->exists("{$target}/profile.jpg"))->toBeFalse()
        ->and($connection->fresh()->payload['_folder'])->toBe('platforms/instagram/1787835720');
    Queue::assertNothingPushed();
});

it('nulls a payload url whose object is not actually on the source prefix', function () {
    // The payload promises a photo; only the profile pic was ever written. Once
    // the source is reclaimed that URL is dead beyond doubt, so it must not be
    // carried across as a live-looking link to the new prefix.
    $connection = legacyMirroredConnection('half-mirrored', '1787835720');
    putMirrorObjects('1787835720', ['profile.jpg']);

    $this->artisan('media:repair-instagram-mirror-prefix')->assertSuccessful();

    $payload = $connection->fresh()->payload;
    $target = InstagramConnectionSeeder::mirrorFolder($connection);

    expect($payload['images'])->toBe([])
        ->and($payload['profilePicUrl'])->toBe("https://media.test/{$target}/profile.jpg");
});

it('refuses a stored folder outside the instagram namespace', function () {
    Queue::fake();
    $user = mirrorRepairUser('stray-folder');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => ['username' => 'stray-folder', '_folder' => 'images/somebody-else'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $this->artisan('media:repair-instagram-mirror-prefix')
        ->expectsOutputToContain('REFUSED')
        ->assertSuccessful();

    expect($connection->fresh()->payload['_folder'])->toBe('images/somebody-else');
    Queue::assertNothingPushed();
});

it('leaves a connection that never mirrored anything alone', function () {
    Queue::fake();
    $user = mirrorRepairUser('pending-connect');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => ['username' => 'pending-connect'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
    ]);

    $this->artisan('media:repair-instagram-mirror-prefix')->assertSuccessful();

    expect($connection->fresh()->payload)->not->toHaveKey('_folder');
    Queue::assertNothingPushed();
});

it('moves a soft-deleted row too, so a restore cannot re-arm the neighbour delete', function () {
    $a = legacyMirroredConnection('still-here', '1787835720');
    $b = legacyMirroredConnection('disconnected', '1787835720');
    putMirrorObjects('1787835720', ['profile.jpg']);
    $b->delete();

    $this->artisan('media:repair-instagram-mirror-prefix')->assertSuccessful();

    $trashed = IntegrationConnection::withTrashed()->find($b->getKey());

    expect($trashed->payload['_folder'])->toBe(InstagramConnectionSeeder::mirrorFolder($b))
        ->and($trashed->payload['_folder'])->not->toBe($a->fresh()->payload['_folder']);
});

it('purges the sitepage edge cache, which saveQuietly would otherwise have skipped', function () {
    // The payload write is quiet on purpose (the observer's saved() chain would
    // re-sync ingest for every row and dispatch the source delete before the
    // group is safe to free) — but a moved profilePicUrl still has to reach the
    // edge, or the sitepage serves the old URL out of cache and 404s once this
    // run reclaims the prefix behind it.
    Queue::fake();
    $connection = legacyMirroredConnection('cache-holder', '1787835720');
    putMirrorObjects('1787835720', ['profile.jpg']);
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $connection->user_id,
        'subdomain' => 'cache-holder',
        'is_published' => 1,
        'settings' => json_encode([]),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $this->artisan('media:repair-instagram-mirror-prefix')->assertSuccessful();

    Queue::assertPushed(CloudflareCachePurgeJob::class);
});

it('moves a row that never got a _folder key, located by the urls it publishes', function () {
    // `_folder` is written alongside a successful seed, so a row whose seed died
    // after mirroring has the objects and the published URLs but no folder key.
    // It is still on the retired prefix and still shares it with whoever else was
    // created that second, so it still has to move — located the way the observer
    // would locate it, off the URLs themselves.
    Queue::fake();
    $user = mirrorRepairUser('folderless');
    $base = 'https://media.test/platforms/instagram/1787835720';
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => [
            'username' => 'folderless',
            'profilePicUrl' => "{$base}/profile.jpg",
            'images' => [],
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);
    putMirrorObjects('1787835720', ['profile.jpg']);

    $this->artisan('media:repair-instagram-mirror-prefix')->assertSuccessful();

    $target = InstagramConnectionSeeder::mirrorFolder($connection);
    $payload = $connection->fresh()->payload;

    expect($payload['_folder'])->toBe($target)
        ->and($payload['profilePicUrl'])->toBe("https://media.test/{$target}/profile.jpg")
        ->and(Storage::disk('media')->exists("{$target}/profile.jpg"))->toBeTrue();
});
