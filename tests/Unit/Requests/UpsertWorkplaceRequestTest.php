<?php

use App\Http\Requests\Api\User\Site\UpsertWorkplaceRequest;
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
    $request = new UpsertWorkplaceRequest;
    $request->merge($payload);

    // Mirror Laravel's pre-validation hook so the trim/normalize stage runs.
    $reflection = new ReflectionMethod($request, 'prepareForValidation');
    $reflection->invoke($request);

    $validator = Validator::make($request->all(), $request->rules());

    return $validator->errors()->toArray();
}

it('accepts a Google-autofill-shaped payload', function () {
    $errors = validateWorkplacePayload([
        'name' => 'Test Salon',
        'address' => '123 Main St, Sydney NSW 2000',
        'latitude' => -33.8688,
        'longitude' => 151.2093,
    ]);

    expect($errors)->toBe([]);
});

it('accepts a manual entry with just a name', function () {
    $errors = validateWorkplacePayload([
        'name' => 'My Home Studio',
    ]);

    expect($errors)->toBe([]);
});

it('accepts a manual entry with name + address', function () {
    $errors = validateWorkplacePayload([
        'name' => 'My Home Studio',
        'address' => '42 Some St',
    ]);

    expect($errors)->toBe([]);
});

it('rejects a payload with no name', function () {
    $errors = validateWorkplacePayload([
        'address' => '42 Some St',
    ]);

    expect($errors)->toHaveKey('name');
});

it('rejects a name over 15 characters', function () {
    $errors = validateWorkplacePayload([
        'name' => str_repeat('a', 16),
    ]);

    expect($errors)->toHaveKey('name');
});

it('accepts a name at exactly 15 characters', function () {
    $errors = validateWorkplacePayload([
        'name' => str_repeat('a', 15),
    ]);

    expect($errors)->toBe([]);
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

it('accepts the previous_website, category and description fields', function () {
    $errors = validateWorkplacePayload([
        'name' => 'Ollies',
        'previous_website' => 'https://old.ollies.example',
        'category' => 'Japanese restaurant',
        'description' => 'From Ollies: the best ramen in town.',
    ]);

    expect($errors)->toBe([]);
});

it('rejects an invalid previous_website URL', function () {
    $errors = validateWorkplacePayload([
        'name' => 'Ollies',
        'previous_website' => 'not a url',
    ]);

    expect($errors)->toHaveKey('previous_website');
});

it('trims whitespace and treats blank strings as null', function () {
    $request = new UpsertWorkplaceRequest;
    $request->merge([
        'name' => '  Studio  ',
        'address' => '   ',
    ]);

    $reflection = new ReflectionMethod($request, 'prepareForValidation');
    $reflection->invoke($request);

    expect($request->input('name'))->toBe('Studio');
    expect($request->input('address'))->toBeNull();
});
