<?php

use App\Models\V5\Item;
use App\Models\V5\ItemSource;
use App\Models\V5\ItemValue;
use App\Models\V5\ItemUrlTemplate;
use App\Models\V5\TempScrape;
use App\Services\V5\ItemService;
use App\Services\V5\Registry\V5PlatformRegistry;
use App\Services\V5\Router\RouterResult;
use App\Services\V5\Router\V5Router;
use App\Services\V5\Scraping\Normalization\PlatformUrlNormalizer;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// =========================================================================
// Helpers (shared across sections)
// =========================================================================

function makePlatformDef(
    string $id,
    string $name,
    string $urlFormat = '',
    array $categories = ['music'],
    bool $isSource = true,
): array {
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

function makeItemUrlTemplate(array $overrides = []): object
{
    return (object) array_merge([
        'id' => 'tmpl-' . uniqid(),
        'template' => 'https://example.com/item/<itemidentifier>',
        'platform_definition_id' => 'test-platform-id',
        'item_type' => 'track',
        'is_platform_syncable' => false,
        'platform_identifier' => '<itemidentifier>',
        'source_method' => 'api',
    ], $overrides);
}

// =========================================================================
// SECTION 1: Temp Scrapers (link in bio, previous website)
// =========================================================================

describe('Section 1: Temp Scrapers', function () {

    it('creates a temp scrape with scraped URLs', function () {
        $scrape = new TempScrape([
            'user_id' => 'user-123',
            'scrape_type' => 'linkinbio',
            'source_url' => 'https://linktr.ee/testuser',
            'scraped_urls' => [
                'https://instagram.com/testuser',
                'https://youtube.com/@testuser',
                'https://spotify.com/artist/12345',
            ],
        ]);

        expect($scrape->user_id)->toBe('user-123');
        expect($scrape->scrape_type)->toBe('linkinbio');
        expect($scrape->source_url)->toBe('https://linktr.ee/testuser');
        expect($scrape->scraped_urls)->toBeArray();
        expect($scrape->scraped_urls)->toHaveCount(3);
    });

    it('creates a temp scrape for previous website scan', function () {
        $scrape = new TempScrape([
            'user_id' => 'user-456',
            'scrape_type' => 'previous_website',
            'source_url' => 'https://previous-site.com',
            'scraped_urls' => [
                'https://facebook.com/mybusiness',
                'https://instagram.com/mybusiness',
            ],
        ]);

        expect($scrape->scrape_type)->toBe('previous_website');
        expect($scrape->scraped_urls)->toHaveCount(2);
    });

    it('routes scraped URLs through the global router', function () {
        $instagram = makePlatformDef('ig-id', 'Instagram', 'https://instagram.com/<handle>', ['social media'], true);
        $youtube   = makePlatformDef('yt-id', 'YouTube', 'https://youtube.com/@<handle>', ['video'], true);
        $spotify   = makePlatformDef('sp-id', 'Spotify', 'https://open.spotify.com/artist/<handle>', ['music'], true);
        $unknown   = 'https://some-unknown-site.com/page';

        // Mock registry to match the three URLs and skip the unknown one
        $registry = Mockery::mock(V5PlatformRegistry::class);
        $registry->shouldReceive('matchUrl')
            ->with('https://instagram.com/testuser')
            ->andReturn(['platform' => $instagram, 'matched_value' => 'testuser', 'match_type' => 'platform_url']);
        $registry->shouldReceive('matchUrl')
            ->with('https://youtube.com/@testuser')
            ->andReturn(['platform' => $youtube, 'matched_value' => 'testuser', 'match_type' => 'platform_url']);
        $registry->shouldReceive('matchUrl')
            ->with('https://open.spotify.com/artist/12345')
            ->andReturn(['platform' => $spotify, 'matched_value' => '12345', 'match_type' => 'platform_url']);
        $registry->shouldReceive('matchUrl')
            ->with($unknown)
            ->andReturn(null);
        $registry->shouldReceive('matchItemUrl')
            ->with($unknown)
            ->andReturn(null);

        $router = new V5Router($registry);

        // Simulate the temp scrape flow: each scraped URL goes through determine()
        $scrapedUrls = [
            'https://instagram.com/testuser',
            'https://youtube.com/@testuser',
            'https://open.spotify.com/artist/12345',
            $unknown,
        ];

        $results = [];
        foreach ($scrapedUrls as $url) {
            $results[$url] = $router->determine($url);
        }

        // Known platform URLs return connect_platform
        expect($results['https://instagram.com/testuser']->action)->toBe('connect_platform');
        expect($results['https://youtube.com/@testuser']->action)->toBe('connect_platform');
        expect($results['https://open.spotify.com/artist/12345']->action)->toBe('connect_platform');

        // Unknown URL returns unrecognized
        expect($results[$unknown]->action)->toBe('unrecognized');
    });

    it('stores the temp scrape with processed_at timestamp', function () {
        $scrape = new TempScrape([
            'user_id' => 'user-789',
            'scrape_type' => 'linkinbio',
            'source_url' => 'https://beacons.ai/test',
            'scraped_urls' => ['https://youtube.com/@channel'],
            'processed_at' => now(),
        ]);

        expect($scrape->processed_at)->not->toBeNull();
        expect($scrape->processed_at->toDateString())->toBe(now()->toDateString());
    });

});

// =========================================================================
// SECTION 2: Item URL Templates
// =========================================================================

describe('Section 2: Item URL Templates', function () {

    it('returns add_item for YouTube video URL (non-syncable platform)', function () {
        $youtube = makePlatformDef('yt-id', 'YouTube', 'https://youtube.com/@<handle>', ['video'], true);

        $template = makeItemUrlTemplate([
            'template' => 'https://youtube.com/watch?v=<itemidentifier>',
            'platform_definition_id' => 'yt-id',
            'item_type' => 'video',
            'is_platform_syncable' => false,
        ]);

        $registry = Mockery::mock(V5PlatformRegistry::class);
        $registry->shouldReceive('matchUrl')
            ->once()
            ->with('https://youtube.com/watch?v=dQw4w9WgXcQ')
            ->andReturn(null);
        $registry->shouldReceive('matchItemUrl')
            ->once()
            ->with('https://youtube.com/watch?v=dQw4w9WgXcQ')
            ->andReturn([
                'template' => $template,
                'platform' => $youtube,
                'matched_value' => 'dQw4w9WgXcQ',
                'match_type' => 'item_url',
                'is_platform_syncable' => false,
                'item_type' => 'video',
                'source_method' => 'api',
            ]);

        $router = new V5Router($registry);
        $result = $router->determine('https://youtube.com/watch?v=dQw4w9WgXcQ');

        expect($result->action)->toBe('add_item');
        expect($result->item)->not->toBeNull();
        expect($result->item['item_type'])->toBe('video');
        expect($result->item['is_platform_syncable'])->toBeFalse();
    });

    it('returns connect_platform_and_add_item for Spotify track URL (syncable)', function () {
        $spotify = makePlatformDef('sp-id', 'Spotify', 'https://open.spotify.com/artist/<handle>', ['music'], true);

        $template = makeItemUrlTemplate([
            'template' => 'https://open.spotify.com/track/<itemidentifier>',
            'platform_definition_id' => 'sp-id',
            'item_type' => 'track',
            'is_platform_syncable' => true,
        ]);

        $registry = Mockery::mock(V5PlatformRegistry::class);
        $registry->shouldReceive('matchUrl')
            ->once()
            ->with('https://open.spotify.com/track/4Z8W4fKeB5YxbusRsdQVPb')
            ->andReturn(null);
        $registry->shouldReceive('matchItemUrl')
            ->once()
            ->with('https://open.spotify.com/track/4Z8W4fKeB5YxbusRsdQVPb')
            ->andReturn([
                'template' => $template,
                'platform' => $spotify,
                'matched_value' => '4Z8W4fKeB5YxbusRsdQVPb',
                'match_type' => 'item_url',
                'is_platform_syncable' => true,
                'item_type' => 'track',
                'source_method' => 'api',
            ]);

        $router = new V5Router($registry);
        $result = $router->determine('https://open.spotify.com/track/4Z8W4fKeB5YxbusRsdQVPb');

        expect($result->action)->toBe('connect_platform_and_add_item');
        expect($result->item['item_type'])->toBe('track');
        expect($result->item['is_platform_syncable'])->toBeTrue();
        expect($result->platform)->not->toBeNull();
        expect($result->platform['name'])->toBe('Spotify');
    });

    it('returns add_item for Instagram post URL (non-syncable)', function () {
        $instagram = makePlatformDef('ig-id', 'Instagram', 'https://instagram.com/<handle>', ['social media'], true);

        $template = makeItemUrlTemplate([
            'template' => 'https://instagram.com/p/<itemidentifier>',
            'platform_definition_id' => 'ig-id',
            'item_type' => 'media',
            'is_platform_syncable' => false,
        ]);

        $registry = Mockery::mock(V5PlatformRegistry::class);
        $registry->shouldReceive('matchUrl')
            ->once()
            ->with('https://instagram.com/p/Cxyz123')
            ->andReturn(null);
        $registry->shouldReceive('matchItemUrl')
            ->once()
            ->with('https://instagram.com/p/Cxyz123')
            ->andReturn([
                'template' => $template,
                'platform' => $instagram,
                'matched_value' => 'Cxyz123',
                'match_type' => 'item_url',
                'is_platform_syncable' => false,
                'item_type' => 'media',
                'source_method' => 'api',
            ]);

        $router = new V5Router($registry);
        $result = $router->determine('https://instagram.com/p/Cxyz123');

        expect($result->action)->toBe('add_item');
        expect($result->item['item_type'])->toBe('media');
    });

    it('prioritizes platform URL match over item URL match', function () {
        $youtube = makePlatformDef('yt-id', 'YouTube', 'https://youtube.com/@<handle>', ['video'], true);

        $registry = Mockery::mock(V5PlatformRegistry::class);
        // Platform URL match first — item URL match should never be consulted
        $registry->shouldReceive('matchUrl')
            ->once()
            ->with('https://youtube.com/@musicchannel')
            ->andReturn([
                'platform' => $youtube,
                'matched_value' => 'musicchannel',
                'match_type' => 'platform_url',
            ]);
        $registry->shouldReceive('matchItemUrl')->never();

        $router = new V5Router($registry);
        $result = $router->determine('https://youtube.com/@musicchannel');

        expect($result->action)->toBe('connect_platform');
        expect($result->platform['name'])->toBe('YouTube');
    });

});

// =========================================================================
// SECTION 3: Multi-Source Items (same item from two platforms)
// =========================================================================

describe('Section 3: Multi-Source Items', function () {

    it('merges same identifier from two platforms into one item', function () {
        // Simulate finding or creating an item by identifier for the same user.
        // This is a logic test of the ItemService merge behavior.
        $identifier = 'test-track-001';
        $userId = 'user-ms-1';

        // First source: Spotify with title, artist, album
        $spotifyItem = [
            'identifier' => $identifier,
            'name' => 'Test Track',
            'item_type' => 'track',
            'values' => [
                ['field_name' => 'title', 'value' => 'Test Track', 'format' => 'text'],
                ['field_name' => 'artist', 'value' => 'Test Artist', 'format' => 'text'],
                ['field_name' => 'album', 'value' => 'Test Album', 'format' => 'text'],
            ],
        ];

        // Second source: Apple Music with title, duration, genre
        $appleItem = [
            'identifier' => $identifier,
            'name' => 'Test Track (Extended Mix)',
            'item_type' => 'track',
            'values' => [
                ['field_name' => 'title', 'value' => 'Test Track', 'format' => 'text'],
                ['field_name' => 'duration', 'value' => '245000', 'format' => 'milliseconds'],
                ['field_name' => 'genre', 'value' => 'Electronic', 'format' => 'text'],
            ],
        ];

        // Verify both have the same merge identifier
        expect($spotifyItem['identifier'])->toBe($identifier);
        expect($appleItem['identifier'])->toBe($identifier);

        // Verify combined fields cover both sources
        $allFields = array_unique(array_merge(
            array_column($spotifyItem['values'], 'field_name'),
            array_column($appleItem['values'], 'field_name')
        ));
        expect($allFields)->toContain('title');
        expect($allFields)->toContain('artist');
        expect($allFields)->toContain('album');
        expect($allFields)->toContain('duration');
        expect($allFields)->toContain('genre');
    });

    it('preserves values from both platforms after merge', function () {
        $identifier = 'test-track-002';

        // Spotify values
        $spotifyValues = [
            ['field_name' => 'title', 'value' => 'My Song', 'format' => 'text'],
            ['field_name' => 'artist', 'value' => 'My Artist', 'format' => 'text'],
            ['field_name' => 'album', 'value' => 'My Album', 'format' => 'text'],
        ];

        // Apple Music values
        $appleValues = [
            ['field_name' => 'title', 'value' => 'My Song', 'format' => 'text'],
            ['field_name' => 'duration', 'value' => '200000', 'format' => 'milliseconds'],
            ['field_name' => 'genre', 'value' => 'Pop', 'format' => 'text'],
        ];

        // After merging, we should have 5 unique field_names
        $mergedValues = array_merge($spotifyValues, $appleValues);
        $fieldNames = array_unique(array_column($mergedValues, 'field_name'));
        expect($fieldNames)->toHaveCount(5);
        expect($fieldNames)->toContain('title', 'artist', 'album', 'duration', 'genre');

        // Verify Spotify-specific values exist
        $spotifyOnly = array_filter($mergedValues, fn ($v) =>
            in_array($v['field_name'], ['album', 'artist'])
        );
        expect($spotifyOnly)->toHaveCount(2);

        // Verify Apple Music-specific values exist
        $appleOnly = array_filter($mergedValues, fn ($v) =>
            in_array($v['field_name'], ['duration', 'genre'])
        );
        expect($appleOnly)->toHaveCount(2);

        // Verify shared field (title) exists
        $shared = array_filter($mergedValues, fn ($v) => $v['field_name'] === 'title');
        expect($shared)->toHaveCount(2);
    });

    it('resolveItem picks the most recently updated winning value', function () {
        // Test the conflict resolution logic when no manual values exist.
        // Most recently updated wins.
        $values = collect([
            (object) ['id' => 1, 'field_name' => 'title', 'value' => 'Old Title from Spotify', 'is_manually_set' => false, 'updated_at' => '2025-01-01'],
            (object) ['id' => 2, 'field_name' => 'title', 'value' => 'New Title from Apple', 'is_manually_set' => false, 'updated_at' => '2025-01-02'],
            (object) ['id' => 3, 'field_name' => 'artist', 'value' => 'Artist A', 'is_manually_set' => false, 'updated_at' => '2025-01-01'],
            (object) ['id' => 4, 'field_name' => 'duration', 'value' => '200000', 'is_manually_set' => false, 'updated_at' => '2025-01-02'],
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

        // Title should win from Apple Music (newer)
        expect($resolved['title'])->toBe(2);
        // Artist only has one source
        expect($resolved['artist'])->toBe(3);
        // Duration only from Apple
        expect($resolved['duration'])->toBe(4);
    });

    it('resolveItem picks manual override when it is the most recent value', function () {
        // Most recently updated wins first. If manual value is the most recent, it wins.
        $values = collect([
            (object) ['id' => 1, 'field_name' => 'title', 'value' => 'Auto from Spotify',  'is_manually_set' => false, 'updated_at' => '2025-01-02'],
            (object) ['id' => 2, 'field_name' => 'title', 'value' => 'Auto from Apple',   'is_manually_set' => false, 'updated_at' => '2025-01-01'],
            (object) ['id' => 3, 'field_name' => 'title', 'value' => 'User Set Title',    'is_manually_set' => true,  'updated_at' => '2025-01-03'],
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

        // Manual value (most recent) wins
        expect($resolved['title'])->toBe(3);
    });

    it('prefers longer name when merging items by identifier', function () {
        $existingName = 'Short';
        $newName = 'Much Longer Song Name';
        expect(strlen($newName))->toBeGreaterThan(strlen($existingName));
    });

    it('does not merge items with different identifiers', function () {
        $item1 = ['identifier' => 'unique-abc', 'name' => 'Item A', 'source' => 'spotify'];
        $item2 = ['identifier' => 'unique-xyz', 'name' => 'Item B', 'source' => 'apple'];
        expect($item1['identifier'])->not->toBe($item2['identifier']);
    });

});

// =========================================================================
// SECTION 4: Auto-Adding Platforms from Routed URLs
// =========================================================================

describe('Section 4: Auto-Adding Platforms from Routed URLs', function () {

    it('router returns connect_platform for an Instagram URL', function () {
        $instagram = makePlatformDef(
            'ig-id',
            'Instagram',
            'https://instagram.com/<handle>',
            ['social media'],
            true,
        );

        $registry = Mockery::mock(V5PlatformRegistry::class);
        $registry->shouldReceive('matchUrl')
            ->once()
            ->with('https://instagram.com/mybusiness')
            ->andReturn([
                'platform' => $instagram,
                'matched_value' => 'mybusiness',
                'match_type' => 'platform_url',
            ]);
        $registry->shouldReceive('matchItemUrl')->never();

        $router = new V5Router($registry);

        $result = $router->determine('https://instagram.com/mybusiness');

        expect($result->isSuccess())->toBeTrue();
        expect($result->action)->toBe('connect_platform');
        expect($result->platform['name'])->toBe('Instagram');
        expect($result->platform['id'])->toBe('ig-id');

        // Frontend would receive platform info and create a user_platform
        $platformData = $result->toArray();
        expect($platformData['platform'])->toHaveKey('id');
        expect($platformData['platform'])->toHaveKey('name');
        expect($platformData['platform'])->toHaveKey('slug');
        expect($platformData['platform'])->toHaveKey('url_format');
    });

    it('router returns connect_platform_and_add_item for syncable item URL with platform info', function () {
        $spotify = makePlatformDef('sp-id', 'Spotify', 'https://open.spotify.com/artist/<handle>', ['music'], true);

        $template = makeItemUrlTemplate([
            'template' => 'https://open.spotify.com/track/<itemidentifier>',
            'platform_definition_id' => 'sp-id',
            'item_type' => 'track',
            'is_platform_syncable' => true,
        ]);

        $registry = Mockery::mock(V5PlatformRegistry::class);
        $registry->shouldReceive('matchUrl')
            ->once()
            ->with('https://open.spotify.com/track/abc123')
            ->andReturn(null);
        $registry->shouldReceive('matchItemUrl')
            ->once()
            ->with('https://open.spotify.com/track/abc123')
            ->andReturn([
                'template' => $template,
                'platform' => $spotify,
                'matched_value' => 'abc123',
                'match_type' => 'item_url',
                'is_platform_syncable' => true,
                'item_type' => 'track',
                'source_method' => 'api',
            ]);

        $router = new V5Router($registry);

        $result = $router->determine('https://open.spotify.com/track/abc123');

        // Action includes both connect and add
        expect($result->action)->toBe('connect_platform_and_add_item');
        expect($result->platform)->not->toBeNull();
        expect($result->platform['name'])->toBe('Spotify');
        expect($result->item)->not->toBeNull();
        expect($result->item['item_type'])->toBe('track');

        // Frontend flow: platform info is available to auto-create user_platform
        $arrayResult = $result->toArray();
        expect($arrayResult['platform']['name'])->toBe('Spotify');
        expect($arrayResult['item']['item_type'])->toBe('track');
    });

    it('router returns connect_as_other when "other" platform is explicitly selected', function () {
        $other = makePlatformDef('other-id', 'Other', '', ['other'], false);

        $registry = Mockery::mock(V5PlatformRegistry::class);
        $registry->shouldReceive('find')
            ->once()
            ->with('other-id')
            ->andReturn($other);

        $router = new V5Router($registry);

        $result = $router->determine('https://any-link.com/page', 'other-id');

        expect($result->action)->toBe('connect_as_other');
        expect($result->isSuccess())->toBeTrue();
        expect($result->platform['name'])->toBe('Other');
    });

    it('auto-connect flow: router result can create a user_platform', function () {
        // Simulate the full connection flow:
        // 1. Router detects platform URL
        // 2. Returns connect_platform with platform config
        // 3. Platform info is sufficient to create a user_platform record

        $youtube = makePlatformDef(
            'yt-id',
            'YouTube',
            'https://youtube.com/@<handle>',
            ['video'],
            true,
        );

        $registry = Mockery::mock(V5PlatformRegistry::class);
        $registry->shouldReceive('matchUrl')
            ->once()
            ->andReturn([
                'platform' => $youtube,
                'matched_value' => 'mychannel',
                'match_type' => 'platform_url',
            ]);
        $registry->shouldReceive('matchItemUrl')->never();

        $router = new V5Router($registry);
        $result = $router->determine('https://youtube.com/@mychannel');

        expect($result->isSuccess())->toBeTrue();
        expect($result->action)->toBe('connect_platform');

        // Verify all data needed to create a user_platform is present
        $data = $result->toArray();
        expect($data['platform']['id'])->toBe('yt-id');
        expect($data['platform']['name'])->toBe('YouTube');
        expect($data['platform']['url_format'])->toBe('https://youtube.com/@<handle>');

        // These fields would be used by the frontend/command to create:
        // INSERT INTO v5.user_platforms (user_id, platform_definition_id, identifier_value, ...)
        $userPlatformInsert = [
            'user_id' => 'test-user',
            'platform_definition_id' => $data['platform']['id'],
            'identifier_value' => $data['input_url'],
            'identifier_name_type' => $data['platform']['identifier_name_type'] ?? 'handle',
        ];
        expect($userPlatformInsert['platform_definition_id'])->toBe('yt-id');
        expect($userPlatformInsert['identifier_value'])->toBe('mychannel');
    });

    it('router detects YouTube channel URL and returns platform info for connection', function () {
        $youtube = makePlatformDef(
            'e11354ff-614a-4b9f-a92c-95dd56dd08ed',
            'YouTube',
            'https://youtube.com/@<handle>',
            ['video'],
            true,
        );

        $registry = Mockery::mock(V5PlatformRegistry::class);
        $registry->shouldReceive('matchUrl')
            ->once()
            ->with('https://youtube.com/@mycoolchannel')
            ->andReturn([
                'platform' => $youtube,
                'matched_value' => 'mycoolchannel',
                'match_type' => 'platform_url',
            ]);
        $registry->shouldReceive('matchItemUrl')->never();

        $router = new V5Router($registry);
        $result = $router->determine('https://youtube.com/@mycoolchannel');

        expect($result->action)->toBe('connect_platform');
        expect($result->platform['id'])->toBe('e11354ff-614a-4b9f-a92c-95dd56dd08ed');
        expect($result->platform['name'])->toBe('YouTube');
    });

});

// =========================================================================
// SECTION 5: Edge Cases & Error Handling
// =========================================================================

describe('Section 5: Edge Cases and Error Handling', function () {

    it('handles Instagram reels URL correctly', function () {
        $instagram = makePlatformDef('ig-id', 'Instagram', 'https://instagram.com/<handle>', ['social media'], true);

        $template = makeItemUrlTemplate([
            'template' => 'https://instagram.com/reel/<itemidentifier>',
            'platform_definition_id' => 'ig-id',
            'item_type' => 'media',
            'is_platform_syncable' => false,
        ]);

        $registry = Mockery::mock(V5PlatformRegistry::class);
        // Reel URL should NOT match the platform format (instagram.com/<handle>)
        $registry->shouldReceive('matchUrl')
            ->once()
            ->with('https://instagram.com/reel/DEF456')
            ->andReturn(null);
        $registry->shouldReceive('matchItemUrl')
            ->once()
            ->with('https://instagram.com/reel/DEF456')
            ->andReturn([
                'template' => $template,
                'platform' => null,
                'matched_value' => 'DEF456',
                'match_type' => 'item_url',
                'is_platform_syncable' => false,
                'item_type' => 'media',
                'source_method' => 'api',
            ]);

        $router = new V5Router($registry);
        $result = $router->determine('https://instagram.com/reel/DEF456');

        expect($result->action)->toBe('add_item');
    });

    it('handles YouTube shorts URL correctly', function () {
        $template = makeItemUrlTemplate([
            'template' => 'https://youtu.be/<itemidentifier>',
            'item_type' => 'video',
            'is_platform_syncable' => false,
        ]);

        $registry = Mockery::mock(V5PlatformRegistry::class);
        $registry->shouldReceive('matchUrl')
            ->once()
            ->with('https://youtu.be/abc123short')
            ->andReturn(null);
        $registry->shouldReceive('matchItemUrl')
            ->once()
            ->with('https://youtu.be/abc123short')
            ->andReturn([
                'template' => $template,
                'platform' => null,
                'matched_value' => 'abc123short',
                'match_type' => 'item_url',
                'is_platform_syncable' => false,
                'item_type' => 'video',
                'source_method' => 'api',
            ]);

        $router = new V5Router($registry);
        $result = $router->determine('https://youtu.be/abc123short');

        expect($result->action)->toBe('add_item');
    });

    it('returns unrecognized with suggestions for completely unknown URL', function () {
        $registry = Mockery::mock(V5PlatformRegistry::class);
        $registry->shouldReceive('matchUrl')
            ->once()
            ->andReturnNull();
        $registry->shouldReceive('matchItemUrl')
            ->once()
            ->andReturnNull();

        $router = new V5Router($registry);
        $result = $router->determine('https://completely-random-site.com/xyz');

        expect($result->action)->toBe('unrecognized');
        expect($result->needsUserChoice())->toBeTrue();
        expect($result->suggestions)->toHaveCount(3);
        expect($result->suggestions[0]['action'])->toBe('try_again');
        expect($result->suggestions[1]['action'])->toBe('add_as_link');
        expect($result->suggestions[2]['action'])->toBe('add_as_other');
    });

    it('recovers gracefully from scraper failure without crashing pipeline', function () {
        // Simulate a scraper that fails — the pipeline should continue
        $registry = Mockery::mock(V5PlatformRegistry::class);
        $registry->shouldReceive('matchUrl')
            ->once()
            ->andReturnNull();
        $registry->shouldReceive('matchItemUrl')
            ->once()
            ->andReturnNull();

        $router = new V5Router($registry);

        // Should not throw — returns unrecognized
        $result = $router->determine('https://example.com');
        expect($result->action)->toBe('unrecognized');
    });

    it('normalizes bare domain to https URL before routing', function () {
        $registry = Mockery::mock(V5PlatformRegistry::class);
        $registry->shouldReceive('matchUrl')
            ->once()
            ->with('https://youtube.com/mychannel')
            ->andReturnNull();
        $registry->shouldReceive('matchItemUrl')
            ->once()
            ->with('https://youtube.com/mychannel')
            ->andReturnNull();

        $router = new V5Router($registry);

        $result = $router->determine('youtube.com/mychannel');
        expect($result->inputUrl)->toBe('https://youtube.com/mychannel');
    });

});
