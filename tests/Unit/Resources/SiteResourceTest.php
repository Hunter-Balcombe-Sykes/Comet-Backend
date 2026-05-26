<?php

uses(Tests\TestCase::class)->in(__FILE__);

use App\Http\Resources\SiteResource;
use App\Models\Core\Site\Site;
use Illuminate\Support\Carbon;

it('ships only the allowlisted columns and passes settings through', function () {
    $site = new Site([
        'subdomain' => 'example',
        'theme_id' => '22222222-2222-2222-2222-222222222222',
        'is_published' => true,
        'unpublished_at' => null,
        'settings' => [
            'design' => ['accent_color' => '#ff0000'],
            'booking_mode' => 'manual',
        ],
    ]);
    $site->id = '11111111-1111-1111-1111-111111111111';
    $site->setAttribute('user_id', '99999999-9999-9999-9999-999999999999');
    $site->created_at = Carbon::parse('2026-01-01T00:00:00Z');
    $site->updated_at = Carbon::parse('2026-01-02T00:00:00Z');
    $site->subdomain_changed_at = null;
    $site->setAttribute('internal_flag', 'top-secret');

    $array = (new SiteResource($site))->resolve();

    expect(array_keys($array))->toEqual([
        'id', 'user_id', 'subdomain', 'theme_id', 'is_published',
        'subdomain_changed_at', 'unpublished_at', 'settings',
        'created_at', 'updated_at',
    ]);
    expect($array)->not->toHaveKey('internal_flag');
    expect($array['id'])->toBeString();
    expect($array['settings'])->toBeInstanceOf(stdClass::class);
    // Pass-through: design tokens stay accessible to the dashboard editor.
    // PHP (object) cast only wraps the top level — nested arrays stay arrays.
    expect($array['settings']->design['accent_color'])->toBe('#ff0000');
    expect($array['settings']->booking_mode)->toBe('manual');
});

it('handles empty settings as {} not []', function () {
    $site = new Site([
        'subdomain' => 'example',
        'theme_id' => null,
        'is_published' => false,
        'settings' => [],
    ]);
    $site->id = '11111111-1111-1111-1111-111111111111';

    $array = (new SiteResource($site))->resolve();

    expect($array['settings'])->toBeInstanceOf(stdClass::class);
});
