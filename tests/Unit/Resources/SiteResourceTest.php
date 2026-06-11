<?php

use App\Http\Resources\SiteResource;
use App\Models\Core\Site\Site;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

it('ships only the allowlisted columns and passes non-design settings through', function () {
    $site = new Site([
        'subdomain' => 'example',
        'skeleton_id' => 'skeleton-2',
        'is_published' => true,
        'unpublished_at' => null,
        'settings' => [
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

    // booking_mode is promoted to a top-level key when present in settings
    // (API-1) so the dashboard booking editor and the dedicated
    // updateBookingSettings endpoint share one response shape.
    expect(array_keys($array))->toEqual([
        'id', 'user_id', 'subdomain', 'skeleton_id', 'is_published',
        'subdomain_changed_at', 'unpublished_at', 'settings',
        'created_at', 'updated_at', 'booking_mode',
    ]);
    expect($array)->not->toHaveKey('internal_flag');
    expect($array)->not->toHaveKey('theme_id');
    expect($array['id'])->toBeString();
    expect($array['skeleton_id'])->toBe('skeleton-2');
    expect($array['booking_mode'])->toBe('manual');
    expect($array['settings'])->toBeInstanceOf(stdClass::class);
    // PHP (object) cast only wraps the top level — nested arrays stay arrays.
    expect($array['settings']->booking_mode)->toBe('manual');
});

it('handles empty settings as {} not []', function () {
    $site = new Site([
        'subdomain' => 'example',
        'skeleton_id' => 'skeleton-1',
        'is_published' => false,
        'settings' => [],
    ]);
    $site->id = '11111111-1111-1111-1111-111111111111';

    $array = (new SiteResource($site))->resolve();

    expect($array['settings'])->toBeInstanceOf(stdClass::class);
    // booking_mode / manual_booking_url are omitted (not null) when absent from settings.
    expect($array)->not->toHaveKey('booking_mode');
    expect($array)->not->toHaveKey('manual_booking_url');
});
