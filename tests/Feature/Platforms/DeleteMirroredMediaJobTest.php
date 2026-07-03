<?php

use App\Jobs\Platforms\DeleteMirroredMediaJob;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;

it('fails (and reports) instead of silently returning on a non-platforms prefix', function () {
    Exceptions::fake();
    Storage::fake('media');

    DeleteMirroredMediaJob::dispatchSync('not-platforms/rogue');

    Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), 'non-platforms prefix'));
});

it('deletes a valid platforms/ prefix without reporting', function () {
    Exceptions::fake();
    Storage::fake('media');
    Storage::disk('media')->put('platforms/instagram/123/a.jpg', 'x');

    DeleteMirroredMediaJob::dispatchSync('platforms/instagram/123');

    expect(Storage::disk('media')->exists('platforms/instagram/123/a.jpg'))->toBeFalse();
    Exceptions::assertNothingReported();
});
