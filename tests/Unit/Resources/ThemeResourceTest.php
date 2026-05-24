<?php

uses(Tests\TestCase::class)->in(__FILE__);

use App\Http\Resources\ThemeResource;
use App\Models\Core\Site\Theme;

it('ships only the allowlisted fields and drops extras', function () {
    $theme = new Theme([
        'key' => 'minimal-light',
        'name' => 'Minimal Light',
        'description' => 'Clean serif theme',
        'config' => ['accent' => '#000000'],
        'is_default' => true,
    ]);
    $theme->id = '11111111-1111-1111-1111-111111111111';
    $theme->setAttribute('internal_notes', 'staff only');

    $array = (new ThemeResource($theme))->resolve();

    expect(array_keys($array))->toEqual([
        'id', 'key', 'name', 'description', 'config', 'is_default',
    ]);
    expect($array)->not->toHaveKey('internal_notes');
    expect($array)->not->toHaveKey('created_at');
    expect($array['id'])->toBeString();
    expect($array['config'])->toBeInstanceOf(stdClass::class);
});
