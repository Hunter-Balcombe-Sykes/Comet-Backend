<?php

use App\Http\Requests\Api\BootstrapRequest;
use App\Http\Requests\Api\PublicSite\CustomerLeads\PublicCustomerLeadRequest;
use App\Http\Requests\Api\PublicSite\PublicSiteShowRequest;
use App\Http\Requests\Api\User\Site\DestroyLinkBlockRequest;
use App\Http\Requests\Api\User\Site\ReorderBlocksRequest;
use App\Http\Requests\Api\User\Site\StoreLinkBlockRequest;
use App\Http\Requests\Api\User\Site\UpdateLinkBlockRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

it('rejects missing bootstrap fields', function () {
    $validator = Validator::make([], (new BootstrapRequest)->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('display_name'))->toBeTrue();
    expect($validator->errors()->has('primary_email'))->toBeTrue();
    // phone, first_name, and professional_type are nullable — OAuth sign-ups don't provide them;
    // professional_type is legacy (individual-only platform now)
    expect($validator->errors()->has('phone'))->toBeFalse();
    expect($validator->errors()->has('first_name'))->toBeFalse();
    expect($validator->errors()->has('professional_type'))->toBeFalse();
});

it('rejects invalid public customer lead payload', function () {
    $payload = [
        'full_name' => '',
        'email' => 'bad-email',
        'phone' => str_repeat('1', 51),
    ];

    $validator = Validator::make($payload, (new PublicCustomerLeadRequest)->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('full_name'))->toBeTrue();
    expect($validator->errors()->has('email'))->toBeTrue();
    expect($validator->errors()->has('phone'))->toBeTrue();
});

it('rejects invalid public site subdomain', function () {
    $payload = [
        'subdomain' => 'bad!subdomain',
    ];

    $validator = Validator::make($payload, (new PublicSiteShowRequest)->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('subdomain'))->toBeTrue();
});

it('rejects invalid link block store payload', function () {
    // After the social-link contract refactor, "url is required for custom mode"
    // and the scheme allowlist live in withValidator() (cross-field), not rules().
    // We have to invoke the full Form Request pipeline to exercise both.
    $payload = [
        'title' => str_repeat('a', 81),
        'url' => 'javascript:alert(1)',
    ];

    $request = Request::create('/api/test', 'POST', $payload);
    $formRequest = StoreLinkBlockRequest::createFrom($request);
    $formRequest->setContainer(app())->setRedirector(app('redirect'));

    try {
        $formRequest->validateResolved();
        $errors = collect();
    } catch (ValidationException $e) {
        $errors = collect($e->errors());
    }

    expect($errors->has('title'))->toBeTrue();
    expect($errors->has('url'))->toBeTrue();
});

it('rejects invalid link block update payload', function () {
    // Update request: invalid UUID + disallowed scheme. Both rules + cross-field.
    $payload = [
        'id' => 'not-a-uuid',
        'url' => 'javascript:alert(1)',
    ];

    $request = Request::create('/api/test', 'PATCH', $payload);
    $request->setRouteResolver(function () {
        $route = new Route(['PATCH'], '/api/test', []);
        $route->parameters = ['linkBlock' => 'not-a-uuid'];

        return $route;
    });
    $formRequest = UpdateLinkBlockRequest::createFrom($request);
    $formRequest->setContainer(app())->setRedirector(app('redirect'));

    try {
        $formRequest->validateResolved();
        $errors = collect();
    } catch (ValidationException $e) {
        $errors = collect($e->errors());
    }

    expect($errors->has('id'))->toBeTrue();
    expect($errors->has('url'))->toBeTrue();
});

it('rejects invalid reorder blocks payload', function () {
    $payload = [
        'ids' => ['not-a-uuid'],
    ];

    $validator = Validator::make($payload, (new ReorderBlocksRequest)->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('ids.0'))->toBeTrue();
});

it('rejects invalid destroy link block payload', function () {
    $payload = [
        'id' => 'not-a-uuid',
    ];

    $validator = Validator::make($payload, (new DestroyLinkBlockRequest)->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('id'))->toBeTrue();
});
