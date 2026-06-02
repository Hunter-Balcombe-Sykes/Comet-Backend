<?php

use App\Http\Requests\PublicSite\PublicReportRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

it('validates required fields', function () {
    $rules = (new PublicReportRequest)->rules();
    $v = Validator::make([], $rules);
    expect($v->fails())->toBeTrue();
    expect($v->errors()->keys())->toContain('target_type', 'target_handle', 'reason_code', 'turnstile_token');
});

it('accepts a valid payload', function () {
    $rules = (new PublicReportRequest)->rules();
    $v = Validator::make([
        'target_type' => 'Site',
        'target_handle' => 'joeplumber',
        'reason_code' => 'spam',
        'details' => 'looks like spam',
        'reporter_email' => 'reporter@example.com',
        'turnstile_token' => 'cf-token-here',
    ], $rules);
    expect($v->fails())->toBeFalse();
});

it('rejects details over 4000 chars', function () {
    $rules = (new PublicReportRequest)->rules();
    $v = Validator::make([
        'target_type' => 'Site',
        'target_handle' => 'joeplumber',
        'reason_code' => 'spam',
        'details' => str_repeat('x', 4001),
        'turnstile_token' => 't',
    ], $rules);
    expect($v->errors()->has('details'))->toBeTrue();
});

it('rejects invalid reason_code', function () {
    $rules = (new PublicReportRequest)->rules();
    $v = Validator::make([
        'target_type' => 'Site',
        'target_handle' => 'joeplumber',
        'reason_code' => 'nuked-from-orbit',
        'turnstile_token' => 't',
    ], $rules);
    expect($v->errors()->has('reason_code'))->toBeTrue();
});

it('allows null reporter_email (anonymous report)', function () {
    $rules = (new PublicReportRequest)->rules();
    $v = Validator::make([
        'target_type' => 'Site',
        'target_handle' => 'joeplumber',
        'reason_code' => 'spam',
        'turnstile_token' => 't',
    ], $rules);
    expect($v->errors()->has('reporter_email'))->toBeFalse();
});
