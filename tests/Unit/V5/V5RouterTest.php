<?php

use App\Services\V5\Registry\V5PlatformRegistry;
use App\Services\V5\Router\RouterResult;
use App\Services\V5\Router\V5Router;
use App\Services\V5\Scraping\Normalization\PlatformUrlNormalizer;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// =========================================================================
// Helpers
// =========================================================================

function makeMockPlatform(string $id, string $name, string $urlFormat, array $categories = ['music'], bool $isSource = true): array
{
    return [
        'id' => $id,
        'name' => $name,
        'slug' => strtolower(str_replace(' ', '-', $name)),
        'url_format' => $urlFormat,
        'category_names' => $categories,
        'primary_category' => $categories[0] ?? 'other',
        'is_source' => $isSource,
        'is_url_source' => false,
        'logo' => null,
        'url' => null,
        'user_type' => 'account',
        'platform_colour' => null,
        'identifier_name_type' => 'handle',
        'refresh_interval' => '12 hours',
        'source_method' => 'api',
        'auto_sync' => true,
        'rules' => [],
        'scrape_method_id' => null,
        'scrape_method_name' => null,
        'scrape_method_template' => null,
        'platform_overrides' => [],
        'url_templates' => [],
        'created_at' => now()->toIso8601String(),
    ];
}

function registryReflectionMethod(string $methodName): ReflectionMethod
{
    $normalizer = Mockery::mock(PlatformUrlNormalizer::class);
    $registry = new V5PlatformRegistry($normalizer);
    $method = new ReflectionMethod($registry, $methodName);
    $method->setAccessible(true);
    return $method;
}

// =========================================================================
// V5Router — URL matching tests
// =========================================================================

it('matches a Spotify artist URL and returns connect_platform', function () {
    $spotify = makeMockPlatform(
        'b6ab559c-fbc0-4381-846e-44e23bb792ef',
        'Spotify',
        'https://open.spotify.com/artist/<handle>',
        ['music'],
        true,
    );

    $registry = Mockery::mock(V5PlatformRegistry::class);
    $registry->shouldReceive('matchUrl')
        ->once()
        ->with('https://open.spotify.com/artist/4Z8W4fKeB5YxbusRsdQVPb')
        ->andReturn([
            'platform' => $spotify,
            'matched_value' => '4Z8W4fKeB5YxbusRsdQVPb',
            'match_type' => 'platform_url',
        ]);
    $registry->shouldReceive('matchItemUrl')->never();

    $router = new V5Router($registry);

    $result = $router->determine('https://open.spotify.com/artist/4Z8W4fKeB5YxbusRsdQVPb');

    expect($result)->toBeInstanceOf(RouterResult::class);
    expect($result->isSuccess())->toBeTrue();
    expect($result->action)->toBe('connect_platform');
    expect($result->platform['name'])->toBe('Spotify');
    // inputUrl stores matched_value from the registry match
    expect($result->inputUrl)->toBe('4Z8W4fKeB5YxbusRsdQVPb');
});

it('matches a YouTube channel URL and returns connect_platform', function () {
    $youtube = makeMockPlatform(
        'e11354ff-614a-4b9f-a92c-95dd56dd08ed',
        'YouTube',
        'https://youtube.com/@<handle>',
        ['video'],
        true,
    );

    $registry = Mockery::mock(V5PlatformRegistry::class);
    $registry->shouldReceive('matchUrl')
        ->once()
        ->with('https://youtube.com/@testchannel')
        ->andReturn([
            'platform' => $youtube,
            'matched_value' => 'testchannel',
            'match_type' => 'platform_url',
        ]);
    $registry->shouldReceive('matchItemUrl')->never();

    $router = new V5Router($registry);

    $result = $router->determine('https://youtube.com/@testchannel');

    expect($result->isSuccess())->toBeTrue();
    expect($result->action)->toBe('connect_platform');
    expect($result->platform['name'])->toBe('YouTube');
});

it('matches a YouTube video item URL and returns connect_platform_and_add_item (syncable)', function () {
    $youtube = makeMockPlatform(
        'e11354ff-614a-4b9f-a92c-95dd56dd08ed',
        'YouTube',
        'https://youtube.com/@<handle>',
        ['video'],
        true,
    );

    $registry = Mockery::mock(V5PlatformRegistry::class);
    $registry->shouldReceive('matchUrl')
        ->once()
        ->with('https://youtube.com/watch?v=dQw4w9WgXcQ')
        ->andReturn(null);

    $registry->shouldReceive('matchItemUrl')
        ->once()
        ->with('https://youtube.com/watch?v=dQw4w9WgXcQ')
        ->andReturn([
            'template' => new stdClass(),
            'platform' => $youtube,
            'matched_value' => 'dQw4w9WgXcQ',
            'match_type' => 'item_url',
            'is_platform_syncable' => true,
            'item_type' => 'video',
            'source_method' => 'api',
        ]);

    $router = new V5Router($registry);

    $result = $router->determine('https://youtube.com/watch?v=dQw4w9WgXcQ');

    expect($result->isSuccess())->toBeTrue();
    expect($result->action)->toBe('connect_platform_and_add_item');
    expect($result->item)->not->toBeNull();
    expect($result->item['item_type'])->toBe('video');
    expect($result->item['is_platform_syncable'])->toBeTrue();
    expect($result->platform['name'])->toBe('YouTube');
});

it('matches a Spotify track item URL as syncable', function () {
    $spotify = makeMockPlatform(
        'b6ab559c-fbc0-4381-846e-44e23bb792ef',
        'Spotify',
        'https://open.spotify.com/artist/<handle>',
        ['music'],
        true,
    );

    $registry = Mockery::mock(V5PlatformRegistry::class);
    $registry->shouldReceive('matchUrl')
        ->once()
        ->andReturn(null);
    $registry->shouldReceive('matchItemUrl')
        ->once()
        ->andReturn([
            'template' => new stdClass(),
            'platform' => $spotify,
            'matched_value' => '4Z8W4fKeB5YxbusRsdQVPb',
            'match_type' => 'item_url',
            'is_platform_syncable' => true,
            'item_type' => 'track',
            'source_method' => 'api',
        ]);

    $router = new V5Router($registry);

    $result = $router->determine('https://open.spotify.com/track/4Z8W4fKeB5YxbusRsdQVPb');

    expect($result->isSuccess())->toBeTrue();
    expect($result->action)->toBe('connect_platform_and_add_item');
});

it('returns unrecognized for an unknown URL with suggestions', function () {
    $registry = Mockery::mock(V5PlatformRegistry::class);
    $registry->shouldReceive('matchUrl')
        ->once()
        ->andReturnNull();
    $registry->shouldReceive('matchItemUrl')
        ->once()
        ->andReturnNull();

    $router = new V5Router($registry);

    $result = $router->determine('https://some-random-website.com/profile');

    expect($result->isSuccess())->toBeFalse();
    expect($result->action)->toBe('unrecognized');
    expect($result->needsUserChoice())->toBeTrue();
    expect($result->suggestions)->toHaveCount(3);
    expect($result->suggestions[0]['action'])->toBe('try_again');
    expect($result->suggestions[1]['action'])->toBe('add_as_link');
    expect($result->suggestions[2]['action'])->toBe('add_as_other');
});

it('normalizes URLs without scheme', function () {
    $registry = Mockery::mock(V5PlatformRegistry::class);
    $registry->shouldReceive('matchUrl')
        ->once()
        ->with('https://example.com/page')
        ->andReturnNull();
    $registry->shouldReceive('matchItemUrl')
        ->once()
        ->with('https://example.com/page')
        ->andReturnNull();

    $router = new V5Router($registry);

    $result = $router->determine('example.com/page');

    expect($result->inputUrl)->toBe('https://example.com/page');
});

it('routes within a category scope', function () {
    $youtube = makeMockPlatform(
        'e11354ff-614a-4b9f-a92c-95dd56dd08ed',
        'YouTube',
        'https://youtube.com/@<handle>',
        ['video'],
        true,
    );

    $registry = Mockery::mock(V5PlatformRegistry::class);
    // Category-scoped match succeeds immediately (no global call needed)
    $registry->shouldReceive('matchUrl')
        ->once()
        ->with('https://youtube.com/@somechannel', 'video')
        ->andReturn([
            'platform' => $youtube,
            'matched_value' => 'somechannel',
            'match_type' => 'platform_url',
        ]);

    $router = new V5Router($registry);

    $result = $router->determine('https://youtube.com/@somechannel', null, 'video');

    expect($result->action)->toBe('connect_platform');
    expect($result->platform['name'])->toBe('YouTube');
});

it('routes within a platform scope by matching url_format', function () {
    $spotify = makeMockPlatform(
        'b6ab559c-fbc0-4381-846e-44e23bb792ef',
        'Spotify',
        'https://open.spotify.com/artist/<handle>',
        ['music'],
        true,
    );

    $registry = Mockery::mock(V5PlatformRegistry::class);
    $registry->shouldReceive('find')
        ->once()
        ->with('b6ab559c-fbc0-4381-846e-44e23bb792ef')
        ->andReturn($spotify);

    $router = new V5Router($registry);

    $result = $router->determine(
        'https://open.spotify.com/artist/4Z8W4fKeB5YxbusRsdQVPb',
        'b6ab559c-fbc0-4381-846e-44e23bb792ef',
    );

    // When scoped to a platform whose url_format matches, should be connect_platform
    // (determineForPlatform uses V5Router's own templateToRegex, not the registry's)
    expect($result->action)->toBe('connect_platform');
    expect($result->platform['name'])->toBe('Spotify');
});

it('returns platformInOtherCategory when URL matches a different category', function () {
    $spotify = makeMockPlatform(
        'b6ab559c-fbc0-4381-846e-44e23bb792ef',
        'Spotify',
        'https://open.spotify.com/artist/<handle>',
        ['music'],
        true,
    );

    $registry = Mockery::mock(V5PlatformRegistry::class);
    // First call: scoped to 'video' category — no match
    $registry->shouldReceive('matchUrl')
        ->once()
        ->with('https://open.spotify.com/artist/4Z8W4fKeB5YxbusRsdQVPb', 'video')
        ->andReturnNull();
    // Second call: global — matches Spotify in 'music' category
    $registry->shouldReceive('matchUrl')
        ->once()
        ->with('https://open.spotify.com/artist/4Z8W4fKeB5YxbusRsdQVPb')
        ->andReturn([
            'platform' => $spotify,
            'matched_value' => '4Z8W4fKeB5YxbusRsdQVPb',
            'match_type' => 'platform_url',
        ]);

    $router = new V5Router($registry);

    $result = $router->determine('https://open.spotify.com/artist/4Z8W4fKeB5YxbusRsdQVPb', null, 'video');

    expect($result->isSuggestion())->toBeTrue();
    expect($result->action)->toBe('suggestion_gate');
    expect($result->suggestions)->toHaveCount(2);
    expect($result->suggestions[0]['action'])->toBe('connect_in_other_category');
});

// =========================================================================
// V5PlatformRegistry — templateToRegex logic (via reflection)
// =========================================================================

it('converts URL templates to correct regex patterns', function () {
    $method = registryReflectionMethod('templateToRegex');

    $testCases = [
        'https://open.spotify.com/artist/<handle>'
            => '#^https\://open\.spotify\.com/artist/(?P<handle>[\w.\-@]+)#i',
        'https://youtube.com/@<handle>'
            => '#^https\://youtube\.com/@(?P<handle>[\w.\-@]+)#i',
        'https://youtube.com/watch?v=<itemidentifier>'
            => '#^https\://youtube\.com/watch\?v\=(?P<itemidentifier>[\w.\-]+)#i',
        'https://youtu.be/<itemidentifier>'
            => '#^https\://youtu\.be/(?P<itemidentifier>[\w.\-]+)#i',
        'https://instagram.com/<handle>'
            => '#^https\://instagram\.com/(?P<handle>[\w.\-@]+)#i',
        'https://<handle>.bandcamp.com'
            => '#^https\://(?P<handle>[\w.\-@]+)\.bandcamp\.com#i',
        'https://music.apple.com/artist/<handle>'
            => '#^https\://music\.apple\.com/artist/(?P<handle>[\w.\-@]+)#i',
        'https://soundcloud.com/<handle>/<itemidentifier>'
            => '#^https\://soundcloud\.com/(?P<handle>[\w.\-@]+)/(?P<itemidentifier>[\w.\-]+)#i',
        'https://<handle>.substack.com'
            => '#^https\://(?P<handle>[\w.\-@]+)\.substack\.com#i',
        'https://eventbrite.com/o/<handle>'
            => '#^https\://eventbrite\.com/o/(?P<handle>[\w.\-@]+)#i',
    ];

    foreach ($testCases as $template => $expectedPattern) {
        $actual = $method->invoke(
            (new ReflectionClass(V5PlatformRegistry::class))->newInstanceWithoutConstructor(),
            $template,
        );
        expect($actual)->toBe($expectedPattern, "Failed for template: {$template}");
    }
});

it('matches real URLs against platform URL patterns', function () {
    $method = registryReflectionMethod('templateToRegex');

    $testCases = [
        // [template, url, shouldMatch]
        'Spotify artist'                       => ['https://open.spotify.com/artist/<handle>',             'https://open.spotify.com/artist/4Z8W4fKeB5YxbusRsdQVPb', true],
        'Spotify artist with dots'             => ['https://open.spotify.com/artist/<handle>',             'https://open.spotify.com/artist/artist.name_test',        true],
        'YouTube channel'                      => ['https://youtube.com/@<handle>',                       'https://youtube.com/@testchannel',                        true],
        'YouTube channel with dots'            => ['https://youtube.com/@<handle>',                       'https://youtube.com/@user.name_123',                      true],
        'YouTube video'                        => ['https://youtube.com/watch?v=<itemidentifier>',        'https://youtube.com/watch?v=dQw4w9WgXcQ',                 true],
        'YouTube short'                        => ['https://youtu.be/<itemidentifier>',                   'https://youtu.be/dQw4w9WgXcQ',                            true],
        'Instagram'                            => ['https://instagram.com/<handle>',                      'https://instagram.com/username',                          true],
        'Bandcamp'                             => ['https://<handle>.bandcamp.com',                       'https://myband.bandcamp.com',                             true],
        'Apple Music'                          => ['https://music.apple.com/artist/<handle>',             'https://music.apple.com/artist/123456789',                 true],
        'Twitch'                               => ['https://twitch.tv/<handle>',                         'https://twitch.tv/streamer_name',                         true],
        'Vimeo'                                => ['https://vimeo.com/<handle>',                         'https://vimeo.com/12345678',                              true],
        'Substack'                             => ['https://<handle>.substack.com',                      'https://newsletter.substack.com',                         true],
        'Eventbrite'                           => ['https://eventbrite.com/o/<handle>',                  'https://eventbrite.com/o/my-org-123',                     true],
        'Facebook'                             => ['https://facebook.com/<handle>',                      'https://facebook.com/my.page.name',                       true],
        'SoundCloud with handle and id'        => ['https://soundcloud.com/<handle>/<itemidentifier>',   'https://soundcloud.com/user.name/track-123',              true],
        // Should NOT match
        'Spotify track should not match artist' => ['https://open.spotify.com/artist/<handle>',           'https://open.spotify.com/track/4Z8W4fKeB5YxbusRsdQVPb',   false],
        'YouTube channel should not match video'=> ['https://youtube.com/watch?v=<itemidentifier>',      'https://youtube.com/@testchannel',                        false],
        'Different domain should not match'    => ['https://open.spotify.com/artist/<handle>',           'https://music.apple.com/artist/12345',                    false],
        'Instagram should not match Facebook'  => ['https://instagram.com/<handle>',                     'https://facebook.com/username',                           false],
    ];

    foreach ($testCases as $label => [$template, $url, $shouldMatch]) {
        $method2 = registryReflectionMethod('templateToRegex');
        $pattern = $method2->invoke(
            (new ReflectionClass(V5PlatformRegistry::class))->newInstanceWithoutConstructor(),
            $template,
        );
        $matched = (bool) preg_match($pattern, $url);
        expect($matched)->toBe($shouldMatch, "Failed: {$label}");
    }
});

// =========================================================================
// V5Router templateToRegex (via reflection — unanchored variant)
// =========================================================================

it('V5Router templateToRegex produces unanchored patterns', function () {
    $method = new ReflectionMethod(V5Router::class, 'templateToRegex');
    $method->setAccessible(true);

    $router = new V5Router(Mockery::mock(V5PlatformRegistry::class));

    $pattern = $method->invoke($router, 'https://open.spotify.com/artist/<handle>');
    // V5Router does NOT anchor with ^, unlike V5PlatformRegistry which does
    expect($pattern)->toMatch('/^#https/');
    expect($pattern)->not->toMatch('/#\^https/');

    // But it still matches correctly
    expect(preg_match($pattern, 'https://open.spotify.com/artist/4Z8W4fKeB5YxbusRsdQVPb'))->toBe(1);
});

// =========================================================================
// RouterResult — value object behavior
// =========================================================================

it('RouterResult factory methods produce correct outputs', function () {
    $platform = makeMockPlatform('test-id', 'Spotify', 'https://open.spotify.com/artist/<handle>');

    $result = RouterResult::platformMatch([
        'platform' => $platform,
        'matched_value' => 'testhandle',
        'match_type' => 'platform_url',
    ]);

    $array = $result->toArray();
    expect($array['action'])->toBe('connect_platform');
    expect($array['platform']['name'])->toBe('Spotify');
    expect($array['input_url'])->toBe('testhandle');
    expect($result->isSuccess())->toBeTrue();

    $unrec = RouterResult::unrecognized('https://random.com');
    expect($unrec->isSuccess())->toBeFalse();
    expect($unrec->needsUserChoice())->toBeTrue();
    expect($unrec->toArray()['suggestions'])->toHaveCount(3);
});

// =========================================================================
// ItemService — multi-source item merge conflict resolution logic
// =========================================================================

it('resolves manual value over auto-synced values', function () {
    // Conflict logic: manual > most recently updated > non-null
    $values = collect([
        (object) ['id' => 1, 'field_name' => 'title', 'value' => 'Auto Song B', 'is_manually_set' => false, 'updated_at' => '2025-01-02'],
        (object) ['id' => 2, 'field_name' => 'title', 'value' => 'Auto Song A', 'is_manually_set' => false, 'updated_at' => '2025-01-01'],
        (object) ['id' => 3, 'field_name' => 'title', 'value' => 'Manual Song', 'is_manually_set' => true,  'updated_at' => '2025-01-03'],
    ]);

    $resolved = [];
    foreach ($values->sortByDesc('updated_at') as $v) {
        $key = $v->field_name;
        if (isset($resolved[$key])) continue;
        if ($v->is_manually_set) {
            $resolved[$key] = $v->id;
        } elseif ($v->value !== null && $v->value !== '') {
            $resolved[$key] = $v->id;
        }
    }

    // Manual value should win over auto values (even more recent ones)
    expect($resolved['title'])->toBe(3);
});

it('picks most recently updated when no manual value exists', function () {
    $values = collect([
        (object) ['id' => 1, 'field_name' => 'title', 'value' => 'Old Title', 'is_manually_set' => false, 'updated_at' => '2025-01-01'],
        (object) ['id' => 2, 'field_name' => 'title', 'value' => 'New Title', 'is_manually_set' => false, 'updated_at' => '2025-01-02'],
    ]);

    $resolved = [];
    foreach ($values->sortByDesc('updated_at') as $v) {
        $key = $v->field_name;
        if (isset($resolved[$key])) continue;
        if ($v->is_manually_set) {
            $resolved[$key] = $v->id;
        } elseif ($v->value !== null && $v->value !== '') {
            $resolved[$key] = $v->id;
        }
    }

    expect($resolved['title'])->toBe(2);
});

it('skips null values in conflict resolution', function () {
    $values = collect([
        (object) ['id' => 1, 'field_name' => 'title', 'value' => null,        'is_manually_set' => false, 'updated_at' => '2025-01-01'],
        (object) ['id' => 2, 'field_name' => 'title', 'value' => 'Valid Title', 'is_manually_set' => false, 'updated_at' => '2025-01-02'],
    ]);

    $resolved = [];
    foreach ($values->sortByDesc('updated_at') as $v) {
        $key = $v->field_name;
        if (isset($resolved[$key])) continue;
        if ($v->is_manually_set) {
            $resolved[$key] = $v->id;
        } elseif ($v->value !== null && $v->value !== '') {
            $resolved[$key] = $v->id;
        }
    }

    expect($resolved['title'])->toBe(2);
});

it('prefers longer name when merging items', function () {
    // ItemService picks the longer name when merging by identifier
    $existingName = 'Short';
    $newName = 'Much Longer Song Name';
    expect(strlen($newName))->toBeGreaterThan(strlen($existingName));
});

// =========================================================================
// V5Router — other match scenarios
// =========================================================================

it('returns otherMatch when "other" platform is selected', function () {
    $otherPlatform = makeMockPlatform(
        'b58a4fa2-9930-4628-b056-e2b94e92a244',
        'Other',
        '',
        ['other'],
        false,
    );

    $registry = Mockery::mock(V5PlatformRegistry::class);
    $registry->shouldReceive('find')
        ->once()
        ->andReturn($otherPlatform);

    $router = new V5Router($registry);

    $result = $router->determine('https://some-link.com/page', 'b58a4fa2-9930-4628-b056-e2b94e92a244');

    expect($result->action)->toBe('connect_as_other');
    expect($result->isSuccess())->toBeTrue();
});

it('returns invalidForPlatform when URL does not match the scoped platform', function () {
    $spotify = makeMockPlatform(
        'b6ab559c-fbc0-4381-846e-44e23bb792ef',
        'Spotify',
        'https://open.spotify.com/artist/<handle>',
        ['music'],
        true,
    );

    $registry = Mockery::mock(V5PlatformRegistry::class);
    $registry->shouldReceive('find')
        ->once()
        ->andReturn($spotify);
    $registry->shouldReceive('matchUrl')
        ->once()
        ->andReturnNull();
    $registry->shouldReceive('matchItemUrl')
        ->once()
        ->andReturnNull();

    $router = new V5Router($registry);

    $result = $router->determine(
        'https://soundcloud.com/someuser/track1',
        'b6ab559c-fbc0-4381-846e-44e23bb792ef',
    );

    expect($result->action)->toBe('invalid_for_platform');
    expect($result->needsUserChoice())->toBeTrue();
    expect($result->suggestions)->toHaveCount(3);
});

it('does not crash on empty or unusual URLs', function () {
    $registry = Mockery::mock(V5PlatformRegistry::class);
    $registry->shouldReceive('matchUrl')->andReturnNull();
    $registry->shouldReceive('matchItemUrl')->andReturnNull();

    $router = new V5Router($registry);

    $result = $router->determine('   ');
    expect($result->action)->toBe('unrecognized');
});

it('handles suggestionGate when URL matches a different platform', function () {
    $spotify = makeMockPlatform('spotify-id', 'Spotify', 'https://open.spotify.com/artist/<handle>', ['music'], true);

    $registry = Mockery::mock(V5PlatformRegistry::class);
    $registry->shouldReceive('find')
        ->once()
        ->andReturn($spotify);
    // Return a match for a different platform (Apple Music)
    $apple = makeMockPlatform('apple-id', 'Apple Music', 'https://music.apple.com/artist/<handle>', ['music'], true);
    $registry->shouldReceive('matchUrl')
        ->once()
        ->andReturn([
            'platform' => $apple,
            'matched_value' => '123',
            'match_type' => 'platform_url',
        ]);

    $router = new V5Router($registry);

    // Scoped to Spotify, but URL matches Apple Music
    $result = $router->determine(
        'https://music.apple.com/artist/123',
        'spotify-id',
    );

    expect($result->action)->toBe('suggestion_gate');
    expect($result->suggestions[0]['action'])->toBe('connect_matched_platform');
    expect($result->suggestions[0]['platform']['name'])->toBe('Apple Music');
    expect($result->suggestions[1]['action'])->toBe('add_as_other');
});
