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
        'address_line1' => '123 Main St',
        'city' => 'Sydney',
        'state' => 'NSW',
        'postcode' => '2000',
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
        'address_line1' => '42 Some St',
    ]);

    expect($errors)->toBe([]);
});

it('rejects a payload with no name', function () {
    $errors = validateWorkplacePayload([
        'address_line1' => '42 Some St',
    ]);

    expect($errors)->toHaveKey('name');
});

it('rejects a name over 80 characters', function () {
    $errors = validateWorkplacePayload([
        'name' => str_repeat('a', 81),
    ]);

    expect($errors)->toHaveKey('name');
});

it('accepts a name at exactly 80 characters', function () {
    $errors = validateWorkplacePayload([
        'name' => str_repeat('a', 80),
    ]);

    expect($errors)->toBe([]);
});

it('accepts a real full-length business name (cap raised from 15 — issue 10)', function () {
    $errors = validateWorkplacePayload([
        'name' => 'Oxbridge Barbershop Kensington',
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

// #W2-SEC-7: opening_hours used to be a bare 'array' rule with no shape check.
it('accepts a well-formed opening_hours payload', function () {
    $errors = validateWorkplacePayload([
        'name' => 'Studio',
        'opening_hours' => [
            'mon' => [['open' => '0900', 'close' => '1700']],
            'sat' => [['open' => '1000', 'close' => '1300'], ['open' => '1400', 'close' => '1800']],
            'exceptions' => [],
        ],
    ]);

    expect($errors)->toBe([]);
});

it('rejects an opening_hours key that is not a recognized weekday', function () {
    $errors = validateWorkplacePayload([
        'name' => 'Studio',
        'opening_hours' => ['monday' => [['open' => '0900', 'close' => '1700']]],
    ]);

    expect($errors)->toHaveKey('opening_hours');
});

it('rejects an opening_hours entry missing the open/close shape', function () {
    $errors = validateWorkplacePayload([
        'name' => 'Studio',
        'opening_hours' => ['mon' => ['not-a-shift']],
    ]);

    expect($errors)->toHaveKey('opening_hours');
});

it('rejects an opening_hours entry with a malformed HHMM time', function () {
    $errors = validateWorkplacePayload([
        'name' => 'Studio',
        'opening_hours' => ['mon' => [['open' => '9am', 'close' => '1700']]],
    ]);

    expect($errors)->toHaveKey('opening_hours');
});

it('rejects more than the per-day entry cap', function () {
    $errors = validateWorkplacePayload([
        'name' => 'Studio',
        'opening_hours' => ['mon' => array_fill(0, 9, ['open' => '0900', 'close' => '1000'])],
    ]);

    expect($errors)->toHaveKey('opening_hours');
});

it('rejects an unbounded opening_hours.exceptions list', function () {
    $errors = validateWorkplacePayload([
        'name' => 'Studio',
        'opening_hours' => ['exceptions' => array_fill(0, 51, 'x')],
    ]);

    expect($errors)->toHaveKey('opening_hours');
});

it('trims whitespace and treats blank strings as null', function () {
    $request = new UpsertWorkplaceRequest;
    $request->merge([
        'name' => '  Studio  ',
        'address_line1' => '   ',
    ]);

    $reflection = new ReflectionMethod($request, 'prepareForValidation');
    $reflection->invoke($request);

    expect($request->input('name'))->toBe('Studio');
    expect($request->input('address_line1'))->toBeNull();
});
