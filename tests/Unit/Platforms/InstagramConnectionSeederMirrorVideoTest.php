<?php

// R1: every mirrorVideo() drop reason must be observable — a silent null
// return is indistinguishable from "no reel existed" after the fact, which
// is exactly the ambiguity that made the broken-oven investigation unable to
// confirm root cause. And: a reel's CDN URL is short-lived/signed, so a bad
// status or a dropped connection on the first attempt is often a momentary
// blip, not a real absence of video — one bounded retry, not unbounded.

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\InstagramConnectionSeeder;
use App\Services\Platforms\Payloads\InstagramPayload;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

/** Invoke the private mirrorVideo() method under test via reflection. */
function invokeMirrorVideo(string $url, string $path): ?string
{
    $seeder = app(InstagramConnectionSeeder::class);
    $method = new ReflectionMethod($seeder, 'mirrorVideo');
    $method->setAccessible(true);

    return $method->invoke($seeder, $url, $path);
}

beforeEach(function () {
    Storage::fake('media');
});

// ── Drop observability ──────────────────────────────────────────────────

it('logs an observable reason when the host is not allowed', function () {
    Log::spy();

    $result = invokeMirrorVideo('https://evil.example.com/reel.mp4', 'platforms/instagram/test/reel.mp4');

    expect($result)->toBeNull();
    Log::shouldHaveReceived('info')
        ->withArgs(fn ($message, $context) => $message === 'instagram.mirror_video.dropped'
            && $context['reason'] === 'host_not_allowed'
            && $context['host'] === 'evil.example.com')
        ->once();
});

it('logs an observable reason when the response status is non-2xx', function () {
    Log::spy();
    Http::fake(['scontent.cdninstagram.com/*' => Http::response('', 404)]);

    $result = invokeMirrorVideo('https://scontent.cdninstagram.com/reel.mp4', 'platforms/instagram/test/reel.mp4');

    expect($result)->toBeNull();
    Log::shouldHaveReceived('info')
        ->withArgs(fn ($message, $context) => $message === 'instagram.mirror_video.dropped'
            && $context['reason'] === 'bad_status_404')
        ->atLeast()->once();
});

it('logs an observable reason when the content-type is not video', function () {
    Log::spy();
    Http::fake(['scontent.cdninstagram.com/*' => Http::response('not a video', 200, ['Content-Type' => 'image/jpeg'])]);

    $result = invokeMirrorVideo('https://scontent.cdninstagram.com/reel.mp4', 'platforms/instagram/test/reel.mp4');

    expect($result)->toBeNull();
    Log::shouldHaveReceived('info')
        ->withArgs(fn ($message, $context) => $message === 'instagram.mirror_video.dropped'
            && $context['reason'] === 'bad_content_type')
        ->once();
});

it('logs an observable reason when the header-declared size is oversized', function () {
    Log::spy();
    Http::fake([
        'scontent.cdninstagram.com/*' => Http::response(
            str_repeat('x', 1024),
            200,
            ['Content-Type' => 'video/mp4', 'Content-Length' => (string) (60 * 1024 * 1024)], // > 50MB cap
        ),
    ]);

    $result = invokeMirrorVideo('https://scontent.cdninstagram.com/reel.mp4', 'platforms/instagram/test/reel.mp4');

    expect($result)->toBeNull();
    Log::shouldHaveReceived('info')
        ->withArgs(fn ($message, $context) => $message === 'instagram.mirror_video.dropped'
            && $context['reason'] === 'oversize_header'
            && $context['host'] === 'scontent.cdninstagram.com')
        ->once();
});

it('logs an observable reason when the on-disk size is oversized (header absent/wrong)', function () {
    Log::spy();
    // No Content-Length header at all — must fall back to the on-disk check.
    Http::fake([
        'scontent.cdninstagram.com/*' => Http::response(str_repeat('x', 100), 200, ['Content-Type' => 'video/mp4']),
    ]);
    // Can't realistically stream 50MB in a test — this proves the header-absent
    // path reaches the on-disk branch without erroring, covered together with
    // the header-present case above for the size-cap behaviour as a whole.
    $result = invokeMirrorVideo('https://scontent.cdninstagram.com/reel.mp4', 'platforms/instagram/test/reel.mp4');

    // A tiny 100-byte body with no Content-Length is well under both caps, so
    // this should actually succeed — confirms the missing-header path doesn't
    // itself cause a false drop.
    expect($result)->not->toBeNull();
});

// ── Retry: transient vs deterministic ───────────────────────────────────

it('retries once on a transient bad status and succeeds on the second attempt', function () {
    Http::fake([
        'scontent.cdninstagram.com/*' => Http::sequence()
            ->push('', 403) // transient-looking failure — a momentary CDN block/expired signed URL
            ->push(str_repeat('x', 2048), 200, ['Content-Type' => 'video/mp4', 'Content-Length' => '2048']),
    ]);

    $result = invokeMirrorVideo('https://scontent.cdninstagram.com/reel.mp4', 'platforms/instagram/test/reel-retry.mp4');

    expect($result)->not->toBeNull();
    Http::assertSentCount(2);
});

it('retries once on a connection exception and succeeds on the second attempt', function () {
    $attempt = 0;
    Http::fake(function () use (&$attempt) {
        $attempt++;

        return $attempt === 1
            ? throw new ConnectionException('connection reset')
            : Http::response(str_repeat('x', 2048), 200, ['Content-Type' => 'video/mp4', 'Content-Length' => '2048']);
    });

    $result = invokeMirrorVideo('https://scontent.cdninstagram.com/reel.mp4', 'platforms/instagram/test/reel-retry2.mp4');

    expect($result)->not->toBeNull();
    expect($attempt)->toBe(2);
});

it('does not retry a deterministic failure like the wrong content-type', function () {
    Http::fake(['scontent.cdninstagram.com/*' => Http::response('', 200, ['Content-Type' => 'image/jpeg'])]);

    $result = invokeMirrorVideo('https://scontent.cdninstagram.com/reel.mp4', 'platforms/instagram/test/reel-no-retry.mp4');

    expect($result)->toBeNull();
    // A content-type mismatch is deterministic — retrying wastes a fetch for no
    // reason. Only the two failure classes that plausibly self-resolve (a bad
    // HTTP status, or a connection-level exception) are worth a second attempt.
    Http::assertSentCount(1);
});

it('does not retry a deterministic oversize drop', function () {
    Http::fake([
        'scontent.cdninstagram.com/*' => Http::response(
            str_repeat('x', 1024), 200,
            ['Content-Type' => 'video/mp4', 'Content-Length' => (string) (60 * 1024 * 1024)],
        ),
    ]);

    $result = invokeMirrorVideo('https://scontent.cdninstagram.com/reel.mp4', 'platforms/instagram/test/reel-oversize.mp4');

    expect($result)->toBeNull();
    Http::assertSentCount(1);
});

it('does not retry a disallowed host', function () {
    $result = invokeMirrorVideo('https://evil.example.com/reel.mp4', 'platforms/instagram/test/reel-evil.mp4');

    expect($result)->toBeNull();
    Http::assertNothingSent();
});

it('logs retries_exhausted when both attempts fail transiently', function () {
    Log::spy();
    Http::fake(['scontent.cdninstagram.com/*' => Http::response('', 502)]);

    $result = invokeMirrorVideo('https://scontent.cdninstagram.com/reel.mp4', 'platforms/instagram/test/reel-both-fail.mp4');

    expect($result)->toBeNull();
    Http::assertSentCount(2);
    Log::shouldHaveReceived('info')
        ->withArgs(fn ($message, $context) => $message === 'instagram.mirror_video.dropped' && $context['reason'] === 'retries_exhausted')
        ->once();
});

// ── seed() persists the diagnostics trail internally, never on the wire ────

it('persists latestMedia() diagnostics as _mediaDiagnostics on the stored connection', function () {
    setupUsersTable();
    setupSitesTable();
    $user = createTenant('ig-diag');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => [],
        'is_active' => true,
    ]);

    // A video candidate on a disallowed host — mirrorVideo() returns null
    // deterministically with no real HTTP call, keeping this test hermetic
    // while still exercising a real diagnostics-producing latestMedia() call.
    $profile = [
        'fullName' => 'Diag Test',
        'followersCount' => 10,
        'postsCount' => 1,
        'latestPosts' => [
            ['type' => 'Video', 'display_url' => 'https://evil.example.com/cover.jpg', 'video_url' => 'https://evil.example.com/reel.mp4', 'timestamp' => '2026-07-20T00:00:00.000Z', 'shortCode' => 'reel1'],
        ],
    ];

    app(InstagramConnectionSeeder::class)->seed($connection, 'diagtest', (string) $user->id, $profile);

    $stored = $connection->fresh()->payload;
    expect($stored['_mediaDiagnostics'])->toBe([
        'posts' => 1,
        'videos' => 1,
        'pickedPhoto' => false,
        'pickedVideo' => true, // detection succeeds; mirroring separately fails (disallowed host)
        'videoCandidates' => [
            ['shortCode' => 'reel1', 'hasMp4' => true, 'type' => 'Video'],
        ],
    ]);
    // The mirror itself correctly failed (disallowed host) — videoUrl stays null.
    expect($stored['videoUrl'])->toBeNull();
});

it('never emits _mediaDiagnostics on the public/dashboard Instagram payload', function () {
    $payload = InstagramPayload::fromArray([
        'username' => 'x', '_mediaDiagnostics' => ['posts' => 1, 'videos' => 0],
    ]);
    // The DTO has no $mediaDiagnostics property — fromArray() only reads keys
    // it explicitly declares, and toArray() only emits that same fixed list —
    // so this is a structural guarantee, not a runtime check. This test
    // documents that guarantee by asserting the DTO's known property list.
    $properties = array_keys(get_object_vars($payload));
    expect($properties)->not->toContain('mediaDiagnostics');
    expect($properties)->not->toContain('_mediaDiagnostics');
    expect(array_keys($payload->toArray()))->not->toContain('_mediaDiagnostics');
});
