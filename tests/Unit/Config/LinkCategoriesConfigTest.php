<?php

use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

it('exposes the 7 link categories in config', function () {
    $categories = config('partna.link_categories');

    expect($categories)->toBe(['social', 'booking', 'education', 'content', 'events', 'streaming', 'other']);
});

it('does not include category in the link_block_settings_keys allowlist (it is a column now)', function () {
    // Phase 2: category is a promoted column on site.blocks; it is no longer
    // accepted as a settings sub-key (stripped by migration 20260701180000).
    expect(config('partna.link_block_settings_keys'))->not->toContain('category');
});

it('includes the 16 new platform icon keys in the allowlist', function () {
    $keys = config('partna.link_block_icon_keys');

    foreach ([
        'fresha', 'booksy', 'timely', 'calendly', 'square',
        'stan', 'skool', 'kajabi', 'circle',
        'eventbrite', 'humanitix', 'luma', 'partiful',
        'apple_podcasts', 'substack', 'bandcamp',
    ] as $expected) {
        expect($keys)->toContain($expected);
    }
});

it('each existing social platform has default_category=social and handle_location=path', function () {
    foreach (['instagram', 'facebook', 'linkedin', 'youtube', 'tiktok', 'x', 'spotify', 'soundcloud'] as $key) {
        $config = config("partna.social_platforms.{$key}");
        expect($config['default_category'])->toBe('social', "platform {$key} missing default_category=social");
        expect($config['handle_location'])->toBe('path', "platform {$key} missing handle_location=path");
    }
});

it('registers the 5 booking platforms with default_category=booking', function () {
    foreach (['fresha', 'booksy', 'timely', 'calendly', 'square'] as $key) {
        $config = config("partna.social_platforms.{$key}");
        expect($config)->not->toBeNull("booking platform {$key} not registered");
        expect($config['default_category'])->toBe('booking');
        expect($config['handle_location'])->toBe('path');
        expect($config['url_template'])->toStartWith('https://');
    }
});

it('registers stan and skool as education path-mode platforms', function () {
    foreach (['stan', 'skool'] as $key) {
        $config = config("partna.social_platforms.{$key}");
        expect($config)->not->toBeNull();
        expect($config['default_category'])->toBe('education');
        expect($config['handle_location'])->toBe('path');
    }
});

it('registers kajabi and circle as education subdomain-mode platforms', function () {
    foreach (['kajabi' => 'mykajabi.com', 'circle' => 'circle.so'] as $key => $base) {
        $config = config("partna.social_platforms.{$key}");
        expect($config)->not->toBeNull();
        expect($config['default_category'])->toBe('education');
        expect($config['handle_location'])->toBe('subdomain');
        // In subdomain mode, host_allowlist[0] is the base domain
        expect($config['host_allowlist'][0])->toBe($base);
    }
});

it('registers the 4 event platforms with default_category=events', function () {
    foreach (['eventbrite', 'humanitix', 'luma', 'partiful'] as $key) {
        $config = config("partna.social_platforms.{$key}");
        expect($config)->not->toBeNull();
        expect($config['default_category'])->toBe('events');
        expect($config['handle_location'])->toBe('path');
    }
});

it('registers apple_podcasts as a content path-mode platform', function () {
    $config = config('partna.social_platforms.apple_podcasts');
    expect($config)->not->toBeNull();
    expect($config['default_category'])->toBe('content');
    expect($config['handle_location'])->toBe('path');
    expect($config['host_allowlist'])->toContain('podcasts.apple.com');
});

it('registers substack and bandcamp as content subdomain-mode platforms', function () {
    foreach (['substack' => 'substack.com', 'bandcamp' => 'bandcamp.com'] as $key => $base) {
        $config = config("partna.social_platforms.{$key}");
        expect($config)->not->toBeNull();
        expect($config['default_category'])->toBe('content');
        expect($config['handle_location'])->toBe('subdomain');
        expect($config['host_allowlist'][0])->toBe($base);
    }
});

it('registers twitch and kick as streaming path-mode platforms', function () {
    foreach (['twitch', 'kick'] as $key) {
        $config = config("partna.social_platforms.{$key}");
        expect($config)->not->toBeNull("{$key} not registered in social_platforms");
        expect($config['default_category'])->toBe('streaming');
        expect($config['handle_location'])->toBe('path');
        expect($config['url_template'])->toStartWith('https://');
    }
});

it('streaming_platforms config lists twitch and kick', function () {
    expect(config('partna.streaming_platforms'))->toBe(['twitch', 'kick']);
});

it('every platform defining url_templates (SEM-1 shape map) has a consistent registry: https, {handle}, and url_template is one of the map values', function () {
    $checked = 0;
    foreach (config('partna.social_platforms') as $key => $config) {
        if (! isset($config['url_templates'])) {
            continue;
        }
        $checked++;
        expect($config['url_templates'])->not->toBeEmpty("platform {$key} has an empty url_templates map");
        foreach ($config['url_templates'] as $shape => $template) {
            expect($template)->toStartWith('https://', "platform {$key} url_templates[{$shape}] is not https");
            // toContain()'s 2nd+ args are additional required needles, NOT a
            // message — str_contains() is the correct tool for a single check.
            expect(str_contains($template, '{handle}'))->toBeTrue("platform {$key} url_templates[{$shape}] has no {handle} placeholder");
        }
        expect(in_array($config['url_template'], $config['url_templates'], true))->toBeTrue("platform {$key} url_template is not one of its own url_templates values");
    }

    // Sanity: this test is exercising a real invariant, not vacuously passing
    // because no platform defines url_templates.
    expect($checked)->toBeGreaterThanOrEqual(2);
});

it('live_check_enabled is not in the link_block_settings_keys allowlist (it is a column now)', function () {
    // Phase 2: live_check_enabled is a promoted column on site.blocks; it is no
    // longer accepted as a settings sub-key (stripped by migration 20260701180000).
    expect(config('partna.link_block_settings_keys'))->not->toContain('live_check_enabled');
});
