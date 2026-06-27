<?php

use App\Rules\PlatformInRegistry;
use Illuminate\Support\Facades\Validator;

it('passes a registered platform and fails an unknown one', function () {
    $pass = Validator::make(['platform' => 'fresha'], ['platform' => [new PlatformInRegistry]]);
    expect($pass->passes())->toBeTrue();

    $fail = Validator::make(['platform' => 'myspace'], ['platform' => [new PlatformInRegistry]]);
    expect($fail->fails())->toBeTrue();
});
