<?php

use App\Http\Requests\Api\User\Site\StoreLinkBlockRequest;
use App\Http\Requests\Api\User\Site\UpdateLinkBlockRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Category-rule coverage for StoreLinkBlockRequest and UpdateLinkBlockRequest.
 * Follows the direct-form-request pattern from LinkBlockSocialValidationTest —
 * no DB, no HTTP stack. The controller-level persistence tests live in
 * tests/Feature/Site/LinkBlockCategoryPersistenceTest.php (added in Task 15).
 */
function validateStoreRequestCategory(array $payload): array
{
    $request = Request::create('/api/test', 'POST', $payload);
    $formRequest = StoreLinkBlockRequest::createFrom($request);
    $formRequest->setContainer(app())->setRedirector(app('redirect'));

    try {
        $formRequest->validateResolved();

        return ['ok' => true, 'data' => $formRequest->validated()];
    } catch (ValidationException $e) {
        return ['ok' => false, 'errors' => $e->errors()];
    }
}

function validateUpdateRequestCategory(array $payload, ?string $blockId = null): array
{
    $request = Request::create('/api/test', 'PATCH', $payload);
    $request->setRouteResolver(function () use ($blockId) {
        $route = new Route(['PATCH'], '/api/test', []);
        $route->parameters = ['linkBlock' => $blockId ?? (string) Str::uuid()];

        return $route;
    });

    $formRequest = UpdateLinkBlockRequest::createFrom($request);
    $formRequest->setContainer(app())->setRedirector(app('redirect'));

    try {
        $formRequest->validateResolved();

        return ['ok' => true, 'data' => $formRequest->validated()];
    } catch (ValidationException $e) {
        return ['ok' => false, 'errors' => $e->errors()];
    }
}

// --- Custom mode: category required ---

it('rejects a custom link without category', function () {
    $result = validateStoreRequestCategory([
        'title' => 'My custom',
        'url' => 'https://example.com',
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('category');
});

it('accepts a custom link with a valid category', function () {
    $result = validateStoreRequestCategory([
        'title' => 'My custom',
        'url' => 'https://example.com',
        'category' => 'other',
    ]);

    expect($result['ok'])->toBeTrue();
    expect($result['data']['category'])->toBe('other');
});

it('rejects an invalid category value', function () {
    $result = validateStoreRequestCategory([
        'title' => 'Bad',
        'url' => 'https://example.com',
        'category' => 'not-a-real-category',
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('category');
});

// --- Social mode: category optional (override semantics handled in controller) ---

it('accepts a social link without category (platform default applies in controller)', function () {
    $result = validateStoreRequestCategory([
        'platform' => 'calendly',
        'handle' => 'joshhunter',
    ]);

    expect($result['ok'])->toBeTrue();
});

it('accepts a social link with an explicit category override', function () {
    $result = validateStoreRequestCategory([
        'platform' => 'instagram',
        'handle' => 'joshhunter',
        'category' => 'events',
    ]);

    expect($result['ok'])->toBeTrue();
    expect($result['data']['category'])->toBe('events');
});

it('rejects a social link with an invalid override category', function () {
    $result = validateStoreRequestCategory([
        'platform' => 'instagram',
        'handle' => 'joshhunter',
        'category' => 'not-real',
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('category');
});

// --- Update: category is all-optional but enum-checked when present ---

it('accepts an update with no category (partial update)', function () {
    $result = validateUpdateRequestCategory([
        'title' => 'New title only',
    ]);

    expect($result['ok'])->toBeTrue();
});

it('accepts an update with a valid category', function () {
    $result = validateUpdateRequestCategory([
        'category' => 'content',
    ]);

    expect($result['ok'])->toBeTrue();
    expect($result['data']['category'])->toBe('content');
});

it('rejects an update with an invalid category', function () {
    $result = validateUpdateRequestCategory([
        'category' => 'not-real',
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('category');
});

// --- Defense-in-depth: settings.category is enum-validated too ---

it('rejects an invalid settings.category on create (defense-in-depth)', function () {
    $result = validateStoreRequestCategory([
        'title' => 'My link',
        'url' => 'https://example.com',
        'category' => 'other',
        'settings' => ['category' => 'not-real'],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.category');
});

it('rejects an invalid settings.category on update (defense-in-depth)', function () {
    $result = validateUpdateRequestCategory([
        'settings' => ['category' => 'not-real'],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.category');
});
