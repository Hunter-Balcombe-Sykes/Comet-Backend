<?php

use App\Models\Core\Site\Block;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

it('includes live_check_enabled in fillable', function () {
    expect((new Block)->getFillable())->toContain('live_check_enabled');
});

it('includes category in fillable', function () {
    expect((new Block)->getFillable())->toContain('category');
});

it('includes platform in fillable', function () {
    expect((new Block)->getFillable())->toContain('platform');
});

it('casts live_check_enabled as boolean', function () {
    expect((new Block)->getCasts())->toHaveKey('live_check_enabled', 'boolean');
});
