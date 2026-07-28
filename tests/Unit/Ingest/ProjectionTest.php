<?php

use App\Ingest\ConnectorRegistry;
use App\Ingest\Projection\AppleMusicReleaseProjector;
use App\Ingest\Projection\ApplePodcastsEpisodeProjector;
use App\Ingest\Projection\BandcampReleaseProjector;
use App\Ingest\Projection\FreshaServiceProjector;
use App\Ingest\Projection\GoogleBusinessMediaProjector;
use App\Ingest\Projection\GoogleBusinessReviewProjector;
use App\Ingest\Projection\ProjectorRegistry;
use App\Ingest\Projection\RecordView;
use App\Ingest\Projection\SchemaOrgEventProjector;
use App\Ingest\Projection\SoundcloudChannelProjector;
use App\Ingest\Projection\SpotifyChannelProjector;
use App\Ingest\Projection\SubstackArticleProjector;
use App\Ingest\Projection\VimeoVideoProjector;
use App\Ingest\Projection\YoutubeVideoProjector;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// Tier P: vendor JSON in, structure out. No network, no database, no clock —
// if one of these ever needs setup, the projector stopped being pure.

it('projects a landed release into the typed release shape', function () {
    $view = new RecordView([
        'title' => 'Second Record',
        'url' => 'https://someartist.bandcamp.com/album/second-record',
        'artist' => 'Some Artist',
        'release_date' => '2025-02-02',
        'art_url' => 'https://f4.bcbits.com/img/a222_10.jpg',
        'type' => 'album',
    ]);

    $projected = (new BandcampReleaseProjector)->project($view);

    expect($projected['kind'])->toBe('release')
        ->and($projected['headline'])->toBe('Second Record')
        ->and($projected['facets']['f_link']['url'])->toBe('https://someartist.bandcamp.com/album/second-record')
        ->and($projected['facets']['f_published']['published_from'])->toBe('2025-02-02')
        ->and($projected['facets']['f_authored']['creator'])->toBe('Some Artist')
        ->and($projected['media'])->toHaveCount(1)
        ->and($projected['media'][0]['role'])->toBe('cover');
});

it('projects nothing rather than a nameless item', function () {
    // Reaching this means the record changed shape after landing; producing a
    // blank card would be worse than producing nothing.
    expect((new BandcampReleaseProjector)->project(new RecordView(['url' => 'https://x.bandcamp.com/album/y'])))->toBeNull()
        ->and((new BandcampReleaseProjector)->project(new RecordView(['title' => 'Untitled'])))->toBeNull();
});

it('omits media rather than emitting an empty cover', function () {
    $projected = (new BandcampReleaseProjector)->project(new RecordView([
        'title' => 'No Art', 'url' => 'https://x.bandcamp.com/album/no-art',
    ]));

    expect($projected['media'])->toBe([]);
});

it('records every path a projector consulted', function () {
    // This is what makes the volatility audit possible: a path that is both
    // declared volatile AND read here is a silent correctness hole.
    $view = new RecordView(['title' => 'A', 'url' => 'https://x.bandcamp.com/album/a']);
    (new BandcampReleaseProjector)->project($view);

    expect($view->reads())->toContain('title', 'url', 'release_date', 'artist', 'art_url', 'type');
});

it('reads missing and wrongly-typed paths as absent rather than throwing', function () {
    $view = new RecordView(['count' => 'not-a-number', 'nested' => ['deep' => 'value']]);

    expect($view->string('missing'))->toBeNull()
        ->and($view->int('count'))->toBeNull()
        ->and($view->string('nested.deep'))->toBe('value')
        ->and($view->string('nested.missing'))->toBeNull()
        ->and($view->list('nested'))->toBe(['value'])
        ->and($view->has('nested.deep'))->toBeTrue()
        ->and($view->has('nope'))->toBeFalse();
});

it('is versioned so a changed projector can trigger a rebuild', function () {
    expect(BandcampReleaseProjector::version())->toBeInt()
        ->and(BandcampReleaseProjector::kind())->toBe('release');
});

// ── The registry: every item-targeting stream has a projector ───────────────

it('maps a projector for every registered connector stream that targets an item kind', function () {
    foreach (ConnectorRegistry::all() as $sourceKey => $connectorClass) {
        foreach ($connectorClass::manifest()->streams as $streamName => $spec) {
            if (in_array($spec->target, ['profile_fields', 'none'], true)) {
                expect(ProjectorRegistry::has($sourceKey, $streamName))->toBeFalse(
                    "{$sourceKey}/{$streamName} targets {$spec->target} and must not have a projector",
                );

                continue;
            }

            $projector = ProjectorRegistry::for($sourceKey, $streamName);
            expect($projector)->not->toBeNull("{$sourceKey}/{$streamName} targets item kind '{$spec->target}' but has no projector")
                ->and($projector::kind())->toBe($spec->target, "{$sourceKey}/{$streamName} projector kind must equal the stream target");
        }
    }
});

// ── Per-connector projections: vendor JSON in, typed shape out ──────────────

it('projects an itunes album into a release with genre tag and cover', function () {
    $projected = (new AppleMusicReleaseProjector)->project(new RecordView([
        'collectionId' => '123', 'collectionName' => 'Currents', 'artistName' => 'Tame Impala',
        'releaseDate' => '2015-07-17T07:00:00Z', 'artworkUrl100' => 'https://is1.mzstatic.com/x/100x100bb.jpg',
        'collectionViewUrl' => 'https://music.apple.com/au/album/currents/1440838039', 'primaryGenreName' => 'Psychedelic',
    ]));

    expect($projected['kind'])->toBe('release')
        ->and($projected['headline'])->toBe('Currents')
        ->and($projected['facets']['f_authored']['creator'])->toBe('Tame Impala')
        ->and($projected['facets']['f_published']['published_from'])->toBe('2015-07-17T07:00:00Z')
        ->and($projected['tags'][0])->toBe(['tag' => 'Psychedelic', 'tag_type' => 'genre'])
        ->and($projected['media'][0]['role'])->toBe('cover');
});

it('projects an itunes podcast episode with the show as creator', function () {
    $projected = (new ApplePodcastsEpisodeProjector)->project(new RecordView([
        'trackId' => '900', 'trackName' => 'Some Episode', 'collectionName' => 'The Daily',
        'releaseDate' => '2026-01-05T10:00:00Z', 'trackViewUrl' => 'https://podcasts.apple.com/x',
        'artworkUrl600' => 'https://is1.mzstatic.com/x/600x600bb.jpg', 'description' => 'About things.',
    ]));

    expect($projected['kind'])->toBe('episode')
        ->and($projected['headline'])->toBe('Some Episode')
        ->and($projected['facets']['f_authored']['creator'])->toBe('The Daily')
        ->and($projected['facets']['f_text']['body'])->toBe('About things.');
});

it('projects a spotify oembed into a channel keyed by the entity path from the record key', function () {
    $projected = (new SpotifyChannelProjector)->project(new RecordView(
        ['title' => 'Monstercat', 'thumbnail_url' => 'https://i.scdn.co/image/x', 'html' => '<iframe/>', 'provider_name' => 'Spotify'],
        key: 'artist/4gzpq5DPGxSnKTe4SA8HAU',
    ));

    expect($projected['kind'])->toBe('channel')
        ->and($projected['facets']['f_embed'])->toBe(['provider' => 'spotify', 'embed_key' => 'artist/4gzpq5DPGxSnKTe4SA8HAU'])
        ->and($projected['facets']['f_link']['url'])->toBe('https://open.spotify.com/artist/4gzpq5DPGxSnKTe4SA8HAU')
        ->and($projected['media'][0]['role'])->toBe('avatar');
});

it('projects nothing for a spotify record with no entity key — identity lives in the key', function () {
    expect((new SpotifyChannelProjector)->project(new RecordView(['title' => 'Ghost'])))->toBeNull();
});

it('projects vimeo and youtube entries into videos with embeds and duration where known', function () {
    $vimeo = (new VimeoVideoProjector)->project(new RecordView([
        'id' => '76979871', 'title' => 'A Film', 'url' => 'https://vimeo.com/76979871',
        'upload_date' => '2013-10-16 15:22:26', 'thumbnail_large' => 'https://i.vimeocdn.com/video/x_640.jpg',
        'description' => 'desc', 'duration' => 234,
    ]));
    $youtube = (new YoutubeVideoProjector)->project(new RecordView([
        'id' => 'dQw4w9WgXcQ', 'title' => 'A Video', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        'published' => '2025-06-06T00:00:00+00:00', 'thumbnail' => 'https://i.ytimg.com/vi/x/hq.jpg', 'channel_title' => 'A Channel',
    ]));

    expect($vimeo['kind'])->toBe('video')
        ->and($vimeo['facets']['f_duration']['seconds'])->toBe(234)
        ->and($vimeo['facets']['f_embed'])->toBe(['provider' => 'vimeo', 'embed_key' => '76979871'])
        ->and($youtube['kind'])->toBe('video')
        ->and($youtube['facets']['f_embed'])->toBe(['provider' => 'youtube', 'embed_key' => 'dQw4w9WgXcQ'])
        ->and($youtube['facets']['f_authored']['creator'])->toBe('A Channel');
});

it('projects a substack post into an article', function () {
    $projected = (new SubstackArticleProjector)->project(new RecordView([
        'id' => 'https://pub.substack.com/p/hello', 'title' => 'Hello World',
        'url' => 'https://pub.substack.com/p/hello', 'published' => '2026-02-02T00:00:00Z',
    ]));

    expect($projected['kind'])->toBe('article')
        ->and($projected['headline'])->toBe('Hello World')
        ->and($projected['facets']['f_published']['published_from'])->toBe('2026-02-02T00:00:00Z');
});

it('projects a fresha service parsing duration and price conservatively', function () {
    $projected = (new FreshaServiceProjector)->project(new RecordView([
        'serviceId' => 's:123', 'name' => 'Skin Fade', 'duration' => '1h 30min',
        'description' => 'A proper cut.', 'price' => 'from A$120', 'category' => 'Haircuts',
    ]));

    expect($projected['kind'])->toBe('service')
        ->and($projected['facets']['f_duration']['seconds'])->toBe(5400)
        ->and($projected['offers'][0]['qualifier'])->toBe('from')
        ->and($projected['offers'][0]['amount_minor'])->toBe(12000)
        ->and($projected['offers'][0]['currency'])->toBe('AUD')
        ->and($projected['tags'][0])->toBe(['tag' => 'Haircuts', 'tag_type' => 'category']);
});

it('emits no offer rather than a wrong one for an unparsable fresha price, and never USD-defaults a bare dollar', function () {
    $unparsable = (new FreshaServiceProjector)->project(new RecordView(['serviceId' => 's:1', 'name' => 'Thing', 'price' => 'POA-ish ¯\_(ツ)_/¯']));
    $bare = (new FreshaServiceProjector)->project(new RecordView(['serviceId' => 's:2', 'name' => 'Other', 'price' => '$50']));

    expect($unparsable['offers'])->toBe([])
        ->and($bare['offers'][0]['amount_minor'])->toBe(5000)
        ->and($bare['offers'][0]['currency'])->toBeNull();
});

it('projects a google review honestly when redaction removed the author', function () {
    $projected = (new GoogleBusinessReviewProjector)->project(new RecordView([
        'review_id' => 'places/x/reviews/y', 'rating' => 5,
        'text' => 'Great!', 'publish_time' => '2026-03-03T00:00:00Z', 'published_ago' => '3 months ago',
    ]));

    expect($projected['kind'])->toBe('review')
        ->and($projected['headline'])->toBe('Google review')
        ->and($projected['facets']['f_review']['rating'])->toBe(5.0)
        ->and($projected['facets']['f_rated']['rating_max'])->toBe(5.0)
        // The vendor's relative wording is provenance, never public copy.
        ->and($projected['facets']['f_published']['verbatim'])->toBe('3 months ago');
});

it('projects a places photo as a media item carrying the ref, never a keyed url', function () {
    $projected = (new GoogleBusinessMediaProjector)->project(new RecordView([
        'ref' => 'places/abc/photos/def', 'width_px' => 4032, 'height_px' => 3024,
    ]));

    expect($projected['kind'])->toBe('media')
        ->and($projected['media'][0]['ref'])->toBe('places/abc/photos/def')
        ->and($projected['media'][0]['width'])->toBe(4032)
        ->and(json_encode($projected))->not->toContain('key=');
});

it('projects a schema-org event with occurrence, place, from-offer and cover', function () {
    $projected = (new SchemaOrgEventProjector)->project(new RecordView([
        'name' => 'Spring Show', 'url' => 'https://www.eventbrite.com/e/spring-show-tickets-111',
        'start_date' => '2026-09-20T19:00:00+10:00', 'end_date' => '2026-09-20T23:00:00+10:00',
        'venue' => 'The Corner Hotel', 'locality' => 'Richmond',
        'description' => 'A big night of live music.',
        'price_min' => 25.0, 'currency' => 'AUD', 'availability' => 'available',
        'image' => 'https://img.evbuc.com/banner.jpg',
    ]));

    expect($projected['kind'])->toBe('event')
        ->and($projected['headline'])->toBe('Spring Show')
        ->and($projected['facets']['f_occurrence']['starts_at_local'])->toBe('2026-09-20T19:00:00+10:00')
        // Derived from the embedded offset — pure string math, no clock.
        ->and($projected['facets']['f_occurrence']['starts_at_utc'])->toBe('2026-09-20T09:00:00Z')
        ->and($projected['facets']['f_occurrence']['zone_confidence'])->toBe('offset_only')
        ->and($projected['facets']['f_place']['venue_name'])->toBe('The Corner Hotel')
        ->and($projected['facets']['f_place']['locality'])->toBe('Richmond')
        ->and($projected['offers'][0]['amount_minor'])->toBe(2500)
        ->and($projected['offers'][0]['qualifier'])->toBe('from')
        ->and($projected['media'][0]['role'])->toBe('cover');
});

it('marks a zero-minimum event offer as free and tolerates a dateless event', function () {
    $free = (new SchemaOrgEventProjector)->project(new RecordView([
        'name' => 'Open Day', 'url' => 'https://events.humanitix.com/open-day', 'price_min' => 0.0,
    ]));
    $dateless = (new SchemaOrgEventProjector)->project(new RecordView([
        'name' => 'TBA', 'url' => 'https://events.humanitix.com/tba',
    ]));

    expect($free['offers'][0]['qualifier'])->toBe('free')
        ->and($free['offers'][0]['amount_minor'])->toBe(0)
        ->and($dateless['facets'])->not->toHaveKey('f_occurrence')
        ->and($dateless['offers'])->toBe([]);
});

it('projects nothing rather than a nameless or linkless event', function () {
    expect((new SchemaOrgEventProjector)->project(new RecordView(['url' => 'https://x.com/e/y'])))->toBeNull()
        ->and((new SchemaOrgEventProjector)->project(new RecordView(['name' => 'Ghost Show'])))->toBeNull();
});

it('projects a soundcloud oembed into a channel whose embed key is the parsed player src', function () {
    $projected = (new SoundcloudChannelProjector)->project(new RecordView([
        'title' => 'Forss', 'url' => 'https://soundcloud.com/forss',
        'thumbnail_url' => 'https://i1.sndcdn.com/avatars-000001-t500x500.jpg',
        'embed_url' => 'https://w.soundcloud.com/player/?url=https%3A%2F%2Fapi.soundcloud.com%2Fusers%2F2',
        'author_name' => 'Forss',
    ]));

    expect($projected['kind'])->toBe('channel')
        ->and($projected['headline'])->toBe('Forss')
        ->and($projected['facets']['f_embed']['provider'])->toBe('soundcloud')
        ->and($projected['facets']['f_embed']['embed_key'])->toBe('https://w.soundcloud.com/player/?url=https%3A%2F%2Fapi.soundcloud.com%2Fusers%2F2')
        ->and($projected['facets']['f_channel']['avatar_url'])->toBe('https://i1.sndcdn.com/avatars-000001-t500x500.jpg')
        ->and($projected['media'][0]['role'])->toBe('avatar');
});
