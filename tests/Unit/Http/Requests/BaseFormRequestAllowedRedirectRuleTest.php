<?php

use App\Http\Requests\BaseFormRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

function makeAllowedRedirectRequest(): BaseFormRequest
{
    return new class extends BaseFormRequest
    {
        public function rules(): array
        {
            return [
                'redirect' => ['nullable', 'url', $this->allowedRedirectRule()],
            ];
        }

        public function exposeAllowedRedirectRule(): Closure
        {
            return $this->allowedRedirectRule();
        }
    };
}

function validateRedirect(?string $value): Illuminate\Validation\Validator
{
    $request = makeAllowedRedirectRequest();

    return Validator::make(
        ['redirect' => $value],
        $request->rules(),
    );
}

beforeEach(function () {
    config()->set('app.frontend_url', 'https://app.partna.test');
    config()->set('app.url', 'https://api.partna.test');
});

it('accepts a URL on the configured frontend host', function () {
    $validator = validateRedirect('https://app.partna.test/checkout/success');
    expect($validator->passes())->toBeTrue();
});

it('accepts a URL on the configured backend host', function () {
    $validator = validateRedirect('https://api.partna.test/stripe/return');
    expect($validator->passes())->toBeTrue();
});

it('accepts localhost for local development', function () {
    $validator = validateRedirect('http://localhost:5173/checkout/success');
    expect($validator->passes())->toBeTrue();
});

it('accepts 127.0.0.1 for local development', function () {
    $validator = validateRedirect('http://127.0.0.1:5173/checkout/success');
    expect($validator->passes())->toBeTrue();
});

it('rejects an external host', function () {
    $validator = validateRedirect('https://attacker.example.com/fake-login');
    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->first('redirect'))
        ->toBe('The redirect URL domain is not allowed.');
});

it('rejects a subdomain that is not in the allow-list', function () {
    $validator = validateRedirect('https://evil.app.partna.test.attacker.com/path');
    expect($validator->fails())->toBeTrue();
});

it('short-circuits on empty values so it can stack after nullable', function () {
    $validator = validateRedirect(null);
    expect($validator->passes())->toBeTrue();
});

it('skips an empty string without producing the domain error', function () {
    $request = makeAllowedRedirectRequest();
    $fail = function ($message) use (&$failed) {
        $failed = $message;
    };
    $failed = null;
    ($request->exposeAllowedRedirectRule())('redirect', '', $fail);
    expect($failed)->toBeNull();
});

it('rejects values with no parseable host', function () {
    $request = makeAllowedRedirectRequest();
    $fail = function ($message) use (&$failed) {
        $failed = $message;
    };
    $failed = null;
    ($request->exposeAllowedRedirectRule())('redirect', 'not a url', $fail);
    expect($failed)->toBe('The redirect URL domain is not allowed.');
});
