<?php

/**
 * P3-05 — VideoVariantService must not log or throw absolute filesystem paths
 * (e.g. /tmp/...) from ffmpeg output verbatim.
 *
 * sanitizeOutput() must:
 *   - Strip absolute paths like /tmp/... /var/... /home/...
 *   - Retain the error reason (non-path content)
 *   - Truncate long output to ≤500 chars
 */

use App\Services\Media\VideoVariantService;

function sanitize(string $output): string
{
    $service = app(VideoVariantService::class);
    $ref = new ReflectionMethod($service, 'sanitizeOutput');
    $ref->setAccessible(true);

    return $ref->invoke($service, $output);
}

it('strips /tmp/ absolute paths from ffmpeg output', function () {
    $raw = "/tmp/partna-video-abc123.mp4: Invalid data found when processing input";

    $result = sanitize($raw);

    expect($result)->not->toContain('/tmp/');
    expect($result)->toContain('Invalid data found when processing input');
});

it('strips /var/ paths from ffmpeg output', function () {
    $raw = "Error reading /var/folders/abc/xyz/T/partna-abc.mp4: No such file";

    $result = sanitize($raw);

    expect($result)->not->toContain('/var/');
    expect($result)->toContain('No such file');
});

it('retains the ffmpeg error message after stripping paths', function () {
    $raw = "ffmpeg: /tmp/upload-xyz.mp4: Invalid data found when processing input\nConversion failed!";

    $result = sanitize($raw);

    expect($result)->toContain('Conversion failed');
    expect($result)->toContain('Invalid data found when processing input');
    expect($result)->not->toContain('/tmp/');
});

it('truncates very long output to 500 chars (keeping the tail where the error is)', function () {
    // ffmpeg stderr is verbose at the start; errors are at the end
    $padding = str_repeat('x', 1000);
    $errorLine = 'Conversion failed!';
    $raw = $padding."\n".$errorLine;

    $result = sanitize($raw);

    expect(mb_strlen($result))->toBeLessThanOrEqual(503); // 500 + '…' (3 bytes, 1 char)
    expect($result)->toContain($errorLine);
});

it('sanitizeOutput removes paths from typical poster-frame ffmpeg error output', function () {
    // This is the content that gets passed to Log::warning in extractPoster() —
    // verify the sanitizer strips the /tmp path before it is logged.
    $output = "/tmp/poster-abc.jpg: Invalid data found when processing input\nCannot open file";
    $sanitized = sanitize($output);

    expect($sanitized)->not->toContain('/tmp/');
    expect($sanitized)->toContain('Cannot open file');
});
