<?php

/** @phpstan-ignore-all */

use App\Http\Controllers\Api\User\Uploads\UserUploadController;
use App\Http\Requests\Api\User\Uploads\UploadImageRequest;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\User;
use App\Services\Cache\SiteCacheService;
use App\Services\Media\ImageVariantService;
use App\Services\Media\VideoVariantService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * requests-resources/SEC-2 closed the video-upload validation gap on the
 * premise that ffprobe pre-store validation is a strictly-stronger,
 * fail-closed control than the byte-sniff it replaced. But every other
 * video test in this suite mocks VideoVariantService wholesale
 * (Mockery::mock(VideoVariantService::class)), so that control was
 * verified by NO automated test. These tests deliberately do NOT mock
 * VideoVariantService — they point config('partna.ffprobe_binary') at a
 * throwaway shell-script stub and exercise the real probe() /
 * probeAndValidate() / MediaUploadService / UserUploadController chain,
 * proving the fail-closed behaviour actually fires rather than assuming a
 * mock's promise holds.
 */
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupMediaTables();
    setupFeatureFlagsTable();

    $cache = Mockery::mock(SiteCacheService::class);
    $cache->shouldReceive('invalidateSite')->andReturnNull();
    app()->instance(SiteCacheService::class, $cache);
});

it('probe() throws with the ffprobe-failed message when the binary exits non-zero', function () {
    $stubPath = writeFfprobeStub("#!/bin/sh\necho 'moov atom not found' >&2\nexit 1\n");
    $localVideoPath = tempnam(sys_get_temp_dir(), 'sidest_video_fixture_');
    file_put_contents($localVideoPath, 'contents are irrelevant — the stub ignores its argument');

    try {
        config(['partna.ffprobe_binary' => $stubPath]);

        // Resolve the REAL service from the container — no mock.
        $service = app(VideoVariantService::class);

        expect(fn () => $service->probe($localVideoPath))
            ->toThrow(RuntimeException::class, 'ffprobe failed (exit');
    } finally {
        @unlink($stubPath);
        @unlink($localVideoPath);
    }
});

it('probe() throws with the non-JSON message when the binary emits unparsable output', function () {
    $stubPath = writeFfprobeStub("#!/bin/sh\necho 'not json at all'\nexit 0\n");
    $localVideoPath = tempnam(sys_get_temp_dir(), 'sidest_video_fixture_');
    file_put_contents($localVideoPath, 'contents are irrelevant — the stub ignores its argument');

    try {
        config(['partna.ffprobe_binary' => $stubPath]);

        $service = app(VideoVariantService::class);

        expect(fn () => $service->probe($localVideoPath))
            ->toThrow(RuntimeException::class, 'non-JSON');
    } finally {
        @unlink($stubPath);
        @unlink($localVideoPath);
    }
});

it('rejects a real upload request with 422 and persists no SiteMedia row when ffprobe fails', function () {
    $stubPath = writeFfprobeStub("#!/bin/sh\necho 'moov atom not found' >&2\nexit 1\n");

    try {
        config([
            'partna.ffprobe_binary' => $stubPath,
            'partna.features.video_uploads' => true,
            'queue.default' => 'sync',
        ]);

        [$professional] = createUserAndSiteForVideoProbeRealChainTest();

        // A real UploadedFile::fake() video — no video stream, but that's not
        // the branch under test. The stub fails before content is even read,
        // so the 422 message must carry the STUB's "ffprobe failed (exit"
        // signature, not the "no video stream" message a real ffprobe would
        // give a fake file. That's what proves the stub fired.
        $video = UploadedFile::fake()->create('clip.mp4', 512, 'video/mp4');
        $baseRequest = Request::create('/api/uploads', 'POST', [
            'pool' => 'content',
            'alt_text' => 'Clip',
        ], [], ['video' => $video]);

        /** @var UploadImageRequest $request */
        $request = UploadImageRequest::createFromBase($baseRequest);
        $request->attributes->set('professional', $professional);

        $validator = Mockery::mock(Validator::class);
        $validator->shouldReceive('validated')->andReturn([
            'pool' => 'content',
            'alt_text' => 'Clip',
        ]);
        $request->setValidator($validator);

        // ImageVariantService is unrelated to the video-probe path, but the
        // controller constructor requires it — VideoVariantService is
        // deliberately left as the real, container-resolved service.
        $mediaService = Mockery::mock(ImageVariantService::class);
        app()->instance(ImageVariantService::class, $mediaService);

        $controller = app(UserUploadController::class);
        $response = $controller->upload($request);

        expect($response->getStatusCode())->toBe(422);
        expect($response->getData(true)['message'] ?? null)
            ->toContain('ffprobe failed (exit');

        expect(SiteMedia::query()->count())->toBe(0);
    } finally {
        @unlink($stubPath);
    }
});

/**
 * Writes an executable shell-script stub to a temp path and returns it.
 * Caller is responsible for unlinking it (try/finally in each test).
 */
function writeFfprobeStub(string $script): string
{
    $path = tempnam(sys_get_temp_dir(), 'sidest_ffprobe_stub_');
    file_put_contents($path, $script);
    chmod($path, 0755);

    return $path;
}

/**
 * @return array{0: User, 1: Site}
 */
function createUserAndSiteForVideoProbeRealChainTest(): array
{
    $userId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'handle' => 'probe-chain-pro',
        'handle_lc' => 'probe-chain-pro',
        'display_name' => 'Probe Chain Pro',
        'first_name' => 'Probe',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $userId,
        'subdomain' => 'probe-chain-pro',
        'is_published' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $professional = User::query()->findOrFail($userId);
    $professional->load('site');
    $site = Site::query()->findOrFail($siteId);

    return [$professional, $site];
}
