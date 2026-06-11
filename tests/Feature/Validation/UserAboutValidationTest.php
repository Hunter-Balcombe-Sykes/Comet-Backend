<?php

use App\Http\Requests\Api\User\UpdateUserRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

function validateAboutPayload(array $about): Validator
{
    $request = Request::create('/api/test', 'PATCH', ['about' => $about]);
    $formRequest = UpdateUserRequest::createFrom($request);
    $formRequest->setContainer(app())->setRedirector(app('redirect'));

    try {
        $formRequest->validateResolved();
    } catch (ValidationException $e) {
        return $e->validator;
    }

    return validator($formRequest->validated(), []);
}

it('accepts an empty about object', function () {
    $v = validateAboutPayload([]);
    expect($v->fails())->toBeFalse();
});

it('accepts a full valid about payload', function () {
    $v = validateAboutPayload([
        'credentials' => [
            ['title' => 'Advanced Colourist', 'issuer' => 'Toni & Guy', 'year' => 2019],
        ],
        'experience' => [
            ['role' => 'Senior Stylist', 'place' => 'Rokstar', 'start' => '2021-03', 'end' => null, 'description' => 'Led colour team.'],
        ],
    ]);
    expect($v->fails())->toBeFalse();
});

it('rejects credentials with missing title', function () {
    $v = validateAboutPayload(['credentials' => [['issuer' => 'X', 'year' => 2020]]]);
    expect($v->fails())->toBeTrue();
    expect($v->errors()->has('about.credentials.0.title'))->toBeTrue();
});

it('rejects credentials year out of range', function () {
    $v = validateAboutPayload(['credentials' => [['title' => 'X', 'year' => 1800]]]);
    expect($v->fails())->toBeTrue();
    expect($v->errors()->has('about.credentials.0.year'))->toBeTrue();
});

it('rejects experience with bad start format', function () {
    $v = validateAboutPayload(['experience' => [['role' => 'X', 'start' => '2021/03']]]);
    expect($v->fails())->toBeTrue();
    expect($v->errors()->has('about.experience.0.start'))->toBeTrue();
});

it('rejects experience where end is before start', function () {
    $v = validateAboutPayload(['experience' => [[
        'role' => 'X', 'start' => '2022-06', 'end' => '2021-01',
    ]]]);
    expect($v->fails())->toBeTrue();
    expect($v->errors()->has('about.experience.0.end'))->toBeTrue();
});

it('accepts experience with null end (ongoing role)', function () {
    $v = validateAboutPayload(['experience' => [[
        'role' => 'X', 'start' => '2022-06', 'end' => null,
    ]]]);
    expect($v->fails())->toBeFalse();
});

it('rejects more than 5 credentials', function () {
    $credentials = array_fill(0, 6, ['title' => 'X']);
    $v = validateAboutPayload(['credentials' => $credentials]);
    expect($v->fails())->toBeTrue();
    expect($v->errors()->has('about.credentials'))->toBeTrue();
});

it('rejects more than 5 experience entries', function () {
    $experience = array_fill(0, 6, ['role' => 'X']);
    $v = validateAboutPayload(['experience' => $experience]);
    expect($v->fails())->toBeTrue();
    expect($v->errors()->has('about.experience'))->toBeTrue();
});

it('rejects description over 1000 chars', function () {
    $v = validateAboutPayload(['experience' => [[
        'role' => 'X', 'description' => str_repeat('a', 1001),
    ]]]);
    expect($v->fails())->toBeTrue();
    expect($v->errors()->has('about.experience.0.description'))->toBeTrue();
});

it('strips HTML tags from description', function () {
    // Matches the existing `bio` strip_tags behaviour: tags are removed, inner
    // text content is preserved. Defence-in-depth against stored tags, not
    // sanitisation of tag bodies.
    $request = Request::create('/api/test', 'PATCH', ['about' => [
        'experience' => [['role' => 'X', 'description' => '<b>bold</b> and <i>italic</i> text']],
    ]]);
    $formRequest = UpdateUserRequest::createFrom($request);
    $formRequest->setContainer(app())->setRedirector(app('redirect'));
    $formRequest->validateResolved();

    $validated = $formRequest->validated();
    expect($validated['about']['experience'][0]['description'])->toBe('bold and italic text');
});
