<?php

use App\Http\Requests\Api\User\Site\UpsertGoogleBusinessProfileRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

/**
 * @return array<string, string|null>
 */
function validateWorkplacePayload(array $payload): array
{
    /** @var FormRequest $request */
    $request = new UpsertGoogleBusinessProfileRequest;
    $request->merge($payload);

    // Mirror Laravel's pre-validation hook so the trim/normalize stage runs.
    $reflection = new ReflectionMethod($request, 'prepareForValidation');
    $reflection->setAccessible(true);
    $reflection->invoke($request);

    $validator = Validator::make($request->all(), $request->rules());

    return $validator->errors()->toArray();
}

it('accepts a Google-pick payload with place_id + name', function () {
    $errors = validateWorkplacePayload([
        'place_id' => 'ChIJxxxxxxxxxxxx',
        'name' => 'Test Salon',
        'address' => '123 Main St, Sydney NSW 2000',
        'latitude' => -33.8688,
        'longitude' => 151.2093,
    ]);

    expect($errors)->toBe([]);
});

it('accepts a manual entry without place_id', function () {
    $errors = validateWorkplacePayload([
        'place_id' => null,
        'name' => 'My Home Studio',
        'address' => '42 Some St',
    ]);

    expect($errors)->toBe([]);
});

it('accepts a manual entry with place_id key omitted entirely', function () {
    $errors = validateWorkplacePayload([
        'name' => 'My Home Studio',
    ]);

    expect($errors)->toBe([]);
});

it('rejects a payload with no name', function () {
    $errors = validateWorkplacePayload([
        'place_id' => null,
        'address' => '42 Some St',
    ]);

    expect($errors)->toHaveKey('name');
});

it('rejects an invalid latitude', function () {
    $errors = validateWorkplacePayload([
        'name' => 'Studio',
        'latitude' => 999,
    ]);

    expect($errors)->toHaveKey('latitude');
});

it('rejects an invalid website URL', function () {
    $errors = validateWorkplacePayload([
        'name' => 'Studio',
        'website' => 'not a url',
    ]);

    expect($errors)->toHaveKey('website');
});

it('trims whitespace and treats blank place_id as null', function () {
    $request = new UpsertGoogleBusinessProfileRequest;
    $request->merge([
        'place_id' => '   ',
        'name' => 'Studio',
    ]);

    $reflection = new ReflectionMethod($request, 'prepareForValidation');
    $reflection->setAccessible(true);
    $reflection->invoke($request);

    expect($request->input('place_id'))->toBeNull();
    expect($request->input('name'))->toBe('Studio');
});
