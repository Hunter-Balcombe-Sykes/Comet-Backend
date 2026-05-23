<?php

// Bootstrap the Laravel app so config() is available — SocialLinkNormalizer reads
// the social_platforms registry from config('partna.*') during normalize().
uses(Tests\TestCase::class)->in(__FILE__);

use App\Http\Controllers\Api\Professional\SiteManagement\ProfessionalLinkBlockController;
use App\Services\Site\SocialLinkNormalizer;

function invokeBuildBlockFields(array $data): array
{
    $controller = new ProfessionalLinkBlockController(new SocialLinkNormalizer);
    $method = (new ReflectionClass($controller))->getMethod('buildBlockFields');

    return $method->invoke($controller, $data);
}

it('writes settings.category=other for a custom link with explicit category', function () {
    $fields = invokeBuildBlockFields([
        'title' => 'My link',
        'url' => 'https://example.com',
        'icon_key' => 'link',
        'category' => 'other',
    ]);

    expect($fields['settings']['category'])->toBe('other');
});

it('writes settings.category=booking from platform default (calendly)', function () {
    $fields = invokeBuildBlockFields([
        'platform' => 'calendly',
        'handle' => 'joshhunter',
    ]);

    expect($fields['settings']['category'])->toBe('booking');
    expect($fields['settings']['platform'])->toBe('calendly');
});

it('respects an explicit category override on a platform link', function () {
    $fields = invokeBuildBlockFields([
        'platform' => 'instagram',
        'handle' => 'joshhunter',
        'category' => 'events',
    ]);

    expect($fields['settings']['category'])->toBe('events');
    expect($fields['settings']['platform'])->toBe('instagram');
});

it('throws when a custom link omits category (defensive guard)', function () {
    expect(fn () => invokeBuildBlockFields([
        'title' => 'My link',
        'url' => 'https://example.com',
    ]))->toThrow(InvalidArgumentException::class);
});
