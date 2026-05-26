<?php

use App\Services\BotProtection\Providers\NullProvider;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class)->in(__FILE__);

it('always returns success without making any network call', function () {
    Http::fake();

    $provider = new NullProvider();
    $result = $provider->verify('any-token');

    expect($result->success)->toBeTrue();
    expect($result->wasFailOpen)->toBeFalse();
    Http::assertNothingSent();
});

it('reports its driver name', function () {
    expect((new NullProvider())->driverName())->toBe('null');
});

it('ignores all parameters', function () {
    Http::fake();
    $result = (new NullProvider())->verify('t', '1.2.3.4', 'enquiry', 500);
    expect($result->success)->toBeTrue();
    Http::assertNothingSent();
});
