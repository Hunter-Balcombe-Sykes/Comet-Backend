<?php

use App\Jobs\Platforms\DeleteMirroredMediaJob;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

it('deletes every file under the given platforms folder', function () {
    Storage::fake('media');
    Storage::disk('media')->put('platforms/instagram/123/img-0.jpg', 'x');
    Storage::disk('media')->put('platforms/instagram/123/profile.jpg', 'y');
    // A sibling folder that must be left untouched.
    Storage::disk('media')->put('platforms/instagram/999/keep.jpg', 'z');

    (new DeleteMirroredMediaJob('platforms/instagram/123'))->handle();

    expect(Storage::disk('media')->exists('platforms/instagram/123/img-0.jpg'))->toBeFalse();
    expect(Storage::disk('media')->exists('platforms/instagram/123/profile.jpg'))->toBeFalse();
    expect(Storage::disk('media')->exists('platforms/instagram/999/keep.jpg'))->toBeTrue();
});

it('refuses to delete a prefix outside the platforms/ namespace', function () {
    Storage::fake('media');
    Storage::disk('media')->put('avatars/keep.jpg', 'x');

    // Empty or non-platforms prefixes must be no-ops — never delete broadly.
    (new DeleteMirroredMediaJob(''))->handle();
    (new DeleteMirroredMediaJob('avatars'))->handle();

    expect(Storage::disk('media')->exists('avatars/keep.jpg'))->toBeTrue();
});
