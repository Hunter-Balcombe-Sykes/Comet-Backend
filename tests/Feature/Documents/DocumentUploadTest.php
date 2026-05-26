<?php

use App\Http\Requests\Api\User\Documents\UploadDocumentRequest;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

function validateUploadDocumentRequest(array $payload, array $files = []): array
{
    $request = Request::create('/test', 'POST', $payload, [], $files);
    $formRequest = UploadDocumentRequest::createFrom($request);
    $formRequest->setContainer(app())->setRedirector(app('redirect'));

    try {
        $formRequest->validateResolved();

        return ['valid' => true, 'errors' => []];
    } catch (ValidationException $e) {
        return ['valid' => false, 'errors' => $e->errors()];
    }
}

it('UploadDocumentRequest accepts a valid PDF with title', function () {
    $result = validateUploadDocumentRequest(
        ['title' => 'My Schedule'],
        ['file' => UploadedFile::fake()->create('s.pdf', 100, 'application/pdf')],
    );

    expect($result['errors'] ?? [])->not->toHaveKeys(['file', 'title']);
});

it('UploadDocumentRequest rejects missing title', function () {
    $result = validateUploadDocumentRequest(
        [],
        ['file' => UploadedFile::fake()->create('s.pdf', 100, 'application/pdf')],
    );

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('title');
});

it('UploadDocumentRequest rejects file larger than 10 MB', function () {
    $result = validateUploadDocumentRequest(
        ['title' => 'Huge'],
        ['file' => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')],
    );

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('file');
});

it('UploadDocumentRequest rejects disallowed MIME (docx)', function () {
    $result = validateUploadDocumentRequest(
        ['title' => 'Word Doc'],
        ['file' => UploadedFile::fake()->create('s.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')],
    );

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('file');
});

it('UploadDocumentRequest accepts JPG', function () {
    $result = validateUploadDocumentRequest(
        ['title' => 'Photo schedule'],
        ['file' => UploadedFile::fake()->create('s.jpg', 500, 'image/jpeg')],
    );

    expect($result['errors'] ?? [])->not->toHaveKey('file');
});

it('UploadDocumentRequest accepts PNG', function () {
    $result = validateUploadDocumentRequest(
        ['title' => 'Photo schedule'],
        ['file' => UploadedFile::fake()->create('s.png', 500, 'image/png')],
    );

    expect($result['errors'] ?? [])->not->toHaveKey('file');
});

it('UploadDocumentRequest rejects title longer than 200 chars', function () {
    $result = validateUploadDocumentRequest(
        ['title' => str_repeat('a', 201)],
        ['file' => UploadedFile::fake()->create('s.pdf', 100, 'application/pdf')],
    );

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('title');
});

it('UploadDocumentRequest accepts optional caption within limit', function () {
    $result = validateUploadDocumentRequest(
        ['title' => 'Schedule', 'caption' => str_repeat('b', 200)],
        ['file' => UploadedFile::fake()->create('s.pdf', 100, 'application/pdf')],
    );

    expect($result['errors'] ?? [])->not->toHaveKey('caption');
});

it('route POST api/documents is registered and maps to UserDocumentController@store', function () {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r) => in_array('POST', $r->methods()) && $r->uri() === 'api/documents');

    expect($route)->not->toBeNull();
    expect($route->getActionName())->toContain('UserDocumentController@store');
});

it('route POST api/documents has per-route throttle:10,1 middleware', function () {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r) => in_array('POST', $r->methods()) && $r->uri() === 'api/documents');

    expect($route)->not->toBeNull();
    expect($route->gatherMiddleware())->toContain('throttle:10,1');
});

it('route GET api/documents is registered and maps to UserDocumentController@index', function () {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r) => in_array('GET', $r->methods()) && $r->uri() === 'api/documents');

    expect($route)->not->toBeNull();
    expect($route->getActionName())->toContain('UserDocumentController@index');
});
