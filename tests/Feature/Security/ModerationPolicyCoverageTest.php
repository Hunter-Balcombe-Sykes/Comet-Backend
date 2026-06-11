<?php

use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;
use Illuminate\Support\Facades\Gate;

it('registers a policy for moderation ModerationCase', function () {
    expect(Gate::getPolicyFor(ModerationCase::class))->not->toBeNull();
});

it('registers a policy for moderation Decision', function () {
    expect(Gate::getPolicyFor(Decision::class))->not->toBeNull();
});
