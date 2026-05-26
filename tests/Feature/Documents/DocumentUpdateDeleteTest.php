<?php

use App\Http\Requests\Api\User\Documents\UpdateDocumentRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

function validateUpdateDocumentRequest(array $payload): array
{
    $request = Request::create('/test', 'PATCH', $payload);
    $formRequest = UpdateDocumentRequest::createFrom($request);
    $formRequest->setContainer(app())->setRedirector(app('redirect'));

    try {
        $formRequest->validateResolved();

        return ['valid' => true, 'errors' => []];
    } catch (ValidationException $e) {
        return ['valid' => false, 'errors' => $e->errors()];
    }
}

it('UpdateDocumentRequest accepts title + caption within limits', function () {
    $result = validateUpdateDocumentRequest([
        'title' => 'New title',
        'caption' => 'New caption',
    ]);

    expect($result['valid'])->toBeTrue();
});

it('UpdateDocumentRequest rejects title longer than 200 chars', function () {
    $result = validateUpdateDocumentRequest(['title' => str_repeat('a', 201)]);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('title');
});

it('UpdateDocumentRequest rejects caption longer than 200 chars', function () {
    $result = validateUpdateDocumentRequest(['caption' => str_repeat('a', 201)]);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('caption');
});

it('UpdateDocumentRequest accepts nullable title and caption', function () {
    $result = validateUpdateDocumentRequest(['title' => null, 'caption' => null]);

    expect($result['valid'])->toBeTrue();
});

it('route PATCH api/documents/{document} maps to update', function () {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r) => in_array('PATCH', $r->methods()) && $r->uri() === 'api/documents/{document}');

    expect($route)->not->toBeNull();
    expect($route->getActionName())->toContain('UserDocumentController@update');
});

it('route PATCH api/documents/{document} has throttle:30,1', function () {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r) => in_array('PATCH', $r->methods()) && $r->uri() === 'api/documents/{document}');

    expect($route)->not->toBeNull();
    expect($route->gatherMiddleware())->toContain('throttle:30,1');
});

it('route DELETE api/documents/{document} maps to destroy', function () {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r) => in_array('DELETE', $r->methods()) && $r->uri() === 'api/documents/{document}');

    expect($route)->not->toBeNull();
    expect($route->getActionName())->toContain('UserDocumentController@destroy');
});

it('route DELETE api/documents/{document} has throttle:30,1', function () {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r) => in_array('DELETE', $r->methods()) && $r->uri() === 'api/documents/{document}');

    expect($route)->not->toBeNull();
    expect($route->gatherMiddleware())->toContain('throttle:30,1');
});
