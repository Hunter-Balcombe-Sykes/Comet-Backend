<?php

use App\Http\Requests\Api\User\ImageGallery\UpdateGalleryImageRequest;
use App\Http\Requests\Api\User\Uploads\UploadImageRequest;
use App\Models\Core\Site\SiteMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function validateUploadImageRequest(array $payload): array
{
    $request = Request::create('/test', 'POST', $payload);
    $formRequest = UploadImageRequest::createFrom($request);
    $formRequest->setContainer(app())->setRedirector(app('redirect'));

    try {
        $formRequest->validateResolved();

        return ['valid' => true, 'errors' => []];
    } catch (ValidationException $e) {
        return ['valid' => false, 'errors' => $e->errors()];
    }
}

it('UploadImageRequest accepts caption up to 200 characters', function () {
    $result = validateUploadImageRequest([
        'pool' => 'gallery',
        'caption' => str_repeat('a', 200),
    ]);

    expect($result['errors'] ?? [])->not->toHaveKey('caption');
});

it('UploadImageRequest rejects caption longer than 200 characters', function () {
    $result = validateUploadImageRequest([
        'pool' => 'gallery',
        'caption' => str_repeat('a', 201),
    ]);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('caption');
});

it('UploadImageRequest accepts missing caption (nullable)', function () {
    $result = validateUploadImageRequest([
        'pool' => 'gallery',
    ]);

    expect($result['errors'] ?? [])->not->toHaveKey('caption');
});

it('SiteMedia model accepts caption via mass assignment', function () {
    $media = new SiteMedia([
        'site_id' => (string) Str::uuid(),
        'pool' => 'gallery',
        'path' => 'x.webp',
        'alt_text' => 'alt',
        'caption' => 'My summer shoot',
        'media_type' => 'image',
        'processing_state' => 'ready',
    ]);

    expect($media->caption)->toBe('My summer shoot');
});

it('UpdateGalleryImageRequest accepts caption and alt_text within limits', function () {
    $request = Request::create('/test', 'PATCH', [
        'caption' => 'Before and after haircut',
        'alt_text' => 'Short back and sides',
    ]);
    $formRequest = UpdateGalleryImageRequest::createFrom($request);
    $formRequest->setContainer(app())->setRedirector(app('redirect'));

    $thrown = null;
    try {
        $formRequest->validateResolved();
    } catch (ValidationException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeNull();
});

it('UpdateGalleryImageRequest rejects caption longer than 200 characters', function () {
    $request = Request::create('/test', 'PATCH', [
        'caption' => str_repeat('a', 201),
    ]);
    $formRequest = UpdateGalleryImageRequest::createFrom($request);
    $formRequest->setContainer(app())->setRedirector(app('redirect'));

    $thrown = null;
    try {
        $formRequest->validateResolved();
    } catch (ValidationException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->errors())->toHaveKey('caption');
});

it('route PATCH api/gallery/{image} maps to UserGalleryController@update', function () {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r) => in_array('PATCH', $r->methods()) && $r->uri() === 'api/gallery/{image}');

    expect($route)->not->toBeNull();
    expect($route->getActionName())->toContain('UserGalleryController@update');
});

it('route PATCH api/gallery/{image} has per-route throttle:30,1 middleware', function () {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r) => in_array('PATCH', $r->methods()) && $r->uri() === 'api/gallery/{image}');

    expect($route)->not->toBeNull();
    expect($route->gatherMiddleware())->toContain('throttle:30,1');
});

it('SiteMedia isDirty returns false when caption re-set to same null value', function () {
    // Guards the update() flow that skips save() + cache-invalidation when
    // a PATCH payload matches the existing DB state. Verifies the Eloquent
    // primitive the controller relies on.
    $media = new SiteMedia([
        'site_id' => (string) Str::uuid(),
        'pool' => 'gallery',
        'path' => 'x.webp',
        'alt_text' => null,
        'caption' => null,
        'media_type' => 'image',
        'processing_state' => 'ready',
    ]);
    // Simulate a loaded-from-DB model so isDirty compares against "originals"
    $media->syncOriginal();

    $media->fill(['caption' => null, 'alt_text' => null]);

    expect($media->isDirty(['caption', 'alt_text']))->toBeFalse();
});

it('SiteMedia isDirty returns true when caption changes from null to a value', function () {
    $media = new SiteMedia([
        'site_id' => (string) Str::uuid(),
        'pool' => 'gallery',
        'path' => 'x.webp',
        'alt_text' => null,
        'caption' => null,
        'media_type' => 'image',
        'processing_state' => 'ready',
    ]);
    $media->syncOriginal();

    $media->fill(['caption' => 'New caption']);

    expect($media->isDirty(['caption', 'alt_text']))->toBeTrue();
});
