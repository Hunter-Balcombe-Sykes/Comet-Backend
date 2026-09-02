<?php

// Seed timing (2026-09-02): seed() is the 11-19s between the build's identity
// landing and its media landing, and which of its four vendor waits costs is
// what decides the remedy (plan A.4). This test locks the one
// 'pre_account.seed_timing' log line the remedy gets read back from — one
// line per seed, whole ms, every key present.

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\InstagramConnectionSeeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

it('logs pre_account.seed_timing once with whole-ms non-negative timings', function () {
    Storage::fake('media');
    Log::spy();

    setupUsersTable();
    setupSitesTable();
    $user = createTenant('ig-timing');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => [],
        'is_active' => true,
    ]);

    // A disallowed host on the video candidate keeps this hermetic (no CDN
    // fetch) — same trick as InstagramConnectionSeederMirrorVideoTest's own
    // end-to-end seed() test: mirroring fails deterministically, so no
    // Http::fake is needed for a real scraper/autoSync/identitySync run.
    $profile = [
        'fullName' => 'Timing Test',
        'followersCount' => 5,
        'postsCount' => 1,
        'latestPosts' => [
            ['type' => 'Video', 'display_url' => 'https://evil.example.com/cover.jpg', 'video_url' => 'https://evil.example.com/reel.mp4', 'timestamp' => '2026-07-20T00:00:00.000Z', 'shortCode' => 'reel1'],
        ],
    ];

    app(InstagramConnectionSeeder::class)->seed($connection, 'timingtest', (string) $user->id, $profile);

    Log::shouldHaveReceived('info')
        ->withArgs(function ($message, $context) use ($user) {
            if ($message !== 'pre_account.seed_timing') {
                return false;
            }

            $msKeys = ['latest_ms', 'fresh_ms', 'mirror_photo_ms', 'mirror_pic_ms', 'autosync_ms', 'total_ms'];
            foreach ($msKeys as $key) {
                if (! array_key_exists($key, $context) || ! is_int($context[$key]) || $context[$key] < 0) {
                    return false;
                }
            }

            return $context['user_id'] === (string) $user->id
                && is_bool($context['fresh_refetched'])
                && is_int($context['bio_links'])
                && $context['bio_links'] >= 0;
        })
        ->once();
});
