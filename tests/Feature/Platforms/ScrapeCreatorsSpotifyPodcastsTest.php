<?php

use App\Ingest\Projection\ApplePodcastsEpisodeProjector;
use App\Ingest\Projection\RecordView;
use App\Services\Platforms\ScrapeCreators\SpotifyEpisodesNormalizer;
use App\Services\Platforms\ScrapeCreators\SpotifyPodcastNormalizer;

// Item 11f (2026-09-01): Spotify podcasts + episodes → the listen pool,
// pinned against RECORDED live payloads (/v1/spotify/podcast +
// /v1/spotify/podcast/episodes for Huberman Lab, plus the Spotify-exclusive
// JRE list that answers a RestrictedContent husk mid-list, and the NotFound
// husk that bills a credit as success:true). Two properties, the vendor
// lane's standing frame:
//
//  1. The episodes normalizer lands the SAME vocabulary ApplePodcastsConnector
//     lands, proven by pushing its output through the REAL
//     ApplePodcastsEpisodeProjector — one projector, two sources, no new pool
//     semantics.
//  2. Any other answer shape is a vendor miss (null), never an empty show or
//     catalogue — the husk doctrine, gated on shape, not HTTP status.

function scSpotifyPodcastShowFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-spotify-podcast.json')),
        true
    );
}

function scSpotifyPodcastNotFoundFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-spotify-podcast-notfound.json')),
        true
    );
}

function scSpotifyPodcastEpisodesFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-spotify-podcast-episodes.json')),
        true
    );
}

function scSpotifyPodcastEpisodesMixedFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-spotify-podcast-episodes-mixed.json')),
        true
    );
}

// ── (a) Show endpoint: the identity card ────────────────────────────────────

it('normalizes the recorded show payload into the synthesized identity card', function () {
    $card = app(SpotifyPodcastNormalizer::class)->show(scSpotifyPodcastShowFixture());

    expect($card['id'])->toBe('79CkJF3UJTHFV8Dse3Oy0P')
        ->and($card['name'])->toBe('Huberman Lab')
        ->and($card['publisher'])->toBe('Scicomm Media')
        // Derived, not sharingInfo.shareUrl — no per-request ?si= token.
        ->and($card['url'])->toBe('https://open.spotify.com/show/79CkJF3UJTHFV8Dse3Oy0P')
        // Largest coverArt source — the 640 variant.
        ->and($card['artwork'])->toBe('https://i.scdn.co/image/ab6765630000ba8a66aed32f8066a72781b3b12a')
        // description, not htmlDescription — the card is plain text.
        ->and($card['description'])->toStartWith('The Huberman Lab podcast is hosted by Andrew Huberman')
        ->and($card['description'])->not->toContain('<p>');
});

it('reads the recorded NotFound husk and malformed show shapes as vendor misses', function () {
    $normalizer = app(SpotifyPodcastNormalizer::class);

    $nameless = scSpotifyPodcastShowFixture();
    $nameless['name'] = '';

    // The recorded husk bills a credit with success:true — __typename gates.
    expect($normalizer->show(scSpotifyPodcastNotFoundFixture()))->toBeNull()
        ->and($normalizer->show(['success' => true, 'credits_charged' => 1]))->toBeNull()
        ->and($normalizer->show(['success' => false, 'message' => 'nope']))->toBeNull()
        ->and($normalizer->show($nameless))->toBeNull();
});

// ── (b) Episodes endpoint: the apple-podcasts listen vocabulary ─────────────

it('normalizes recorded episodes into the exact apple-podcasts listen vocabulary', function () {
    $rows = app(SpotifyEpisodesNormalizer::class)->episodes(scSpotifyPodcastEpisodesFixture());

    expect($rows)->toHaveCount(3);

    $episode = $rows[0];
    expect($episode['trackId'])->toBe('304elIxDVbXQ7dBO4G2N4e')
        ->and($episode['trackName'])->toBe('How to Accelerate Learning & Improve Education | Joe Liemandt')
        // The show rides as collectionName — the projector's creator credit.
        ->and($episode['collectionName'])->toBe('Huberman Lab')
        ->and($episode['releaseDate'])->toBe('2026-08-31T08:00:00Z')
        ->and($episode['artworkUrl600'])->toBe('https://i.scdn.co/image/ab6765630000ba8a66aed32f8066a72781b3b12a')
        // Derived from the id, not sharingInfo.shareUrl — no ?si= token.
        ->and($episode['trackViewUrl'])->toBe('https://open.spotify.com/episode/304elIxDVbXQ7dBO4G2N4e')
        ->and($episode['description'])->toContain('Joe Liemandt');

    expect(collect($rows)->pluck('trackId')->all())
        ->toBe(['304elIxDVbXQ7dBO4G2N4e', '2DtV1bEeAI0sudUJRU49Tf', '3mD9drho2ue4CNmMSyAl4e']);
});

it('projects a normalized episode through the real apple-podcasts projector unchanged', function () {
    $rows = app(SpotifyEpisodesNormalizer::class)->episodes(scSpotifyPodcastEpisodesFixture());

    $item = (new ApplePodcastsEpisodeProjector)->project(new RecordView($rows[0], $rows[0]['trackId']));

    expect($item['kind'])->toBe('episode')
        ->and($item['headline'])->toBe('How to Accelerate Learning & Improve Education | Joe Liemandt')
        ->and($item['facets']['f_link']['url'])->toBe('https://open.spotify.com/episode/304elIxDVbXQ7dBO4G2N4e')
        ->and($item['facets']['f_published']['published_from'])->toBe('2026-08-31T08:00:00Z')
        ->and($item['facets']['f_authored']['creator'])->toBe('Huberman Lab')
        ->and($item['facets']['f_text']['body'])->toContain('Joe Liemandt')
        ->and($item['media'][0])->toBe([
            'role' => 'cover',
            'url' => 'https://i.scdn.co/image/ab6765630000ba8a66aed32f8066a72781b3b12a',
        ]);
});

it('skips the recorded mid-list RestrictedContent husk without dropping the list', function () {
    // JRE is a Spotify exclusive: one entry in its recorded list is a
    // {"__typename": "RestrictedContent"} husk riding among real episodes.
    $rows = app(SpotifyEpisodesNormalizer::class)->episodes(scSpotifyPodcastEpisodesMixedFixture());

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['trackId'])->toBe('71Q6UzLG4QoN7lRw5SmUxf')
        ->and($rows[0]['trackName'])->toBe('#2547 - Daniel Everett')
        ->and($rows[0]['collectionName'])->toBe('The Joe Rogan Experience')
        ->and($rows[0]['trackViewUrl'])->toBe('https://open.spotify.com/episode/71Q6UzLG4QoN7lRw5SmUxf');
});

it('reads an episodes husk or an all-restricted list as a vendor miss, never an empty catalogue', function () {
    $normalizer = app(SpotifyEpisodesNormalizer::class);

    expect($normalizer->episodes(['success' => true, 'credits_charged' => 1]))->toBeNull()
        ->and($normalizer->episodes(['success' => true, 'episodes' => [], 'cursor' => null]))->toBeNull()
        ->and($normalizer->episodes(['success' => false, 'message' => 'nope']))->toBeNull()
        // Every entry restricted: zero usable rows must fall through — only
        // the fallback lane may settle an empty catalogue as truth.
        ->and($normalizer->episodes([
            'success' => true,
            'episodes' => [['__typename' => 'RestrictedContent'], ['__typename' => 'RestrictedContent']],
        ]))->toBeNull();
});

it('drops id-less and nameless entries on shape alone', function () {
    $rows = app(SpotifyEpisodesNormalizer::class)->episodes([
        'success' => true,
        'episodes' => [
            ['__typename' => 'Episode', 'id' => 'keeper1', 'name' => 'Keeper'],
            ['__typename' => 'Episode', 'name' => 'No id'],
            ['__typename' => 'Episode', 'id' => 'x2', 'name' => ''],
            'not-an-array',
        ],
    ]);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['trackId'])->toBe('keeper1')
        // A minimal entry still lands the full vocabulary, absent keys null.
        ->and($rows[0]['collectionName'])->toBeNull()
        ->and($rows[0]['releaseDate'])->toBeNull()
        ->and($rows[0]['artworkUrl600'])->toBeNull()
        ->and($rows[0]['description'])->toBeNull();
});
