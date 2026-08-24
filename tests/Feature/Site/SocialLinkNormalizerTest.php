<?php

use App\Services\Site\SocialLinkNormalizer;

function normalizer(): SocialLinkNormalizer
{
    return new SocialLinkNormalizer;
}

// --- getPublicRegistry() ---

it('returns a non-empty public registry containing key platforms', function () {
    $registry = normalizer()->getPublicRegistry();
    $keys = collect($registry)->pluck('key')->all();

    expect($registry)->not->toBeEmpty();
    expect($keys)->toContain('instagram', 'twitch', 'kick');
});

it('strips internal validation fields from the public registry', function () {
    $registry = normalizer()->getPublicRegistry();

    foreach ($registry as $entry) {
        expect($entry)->toHaveKeys(['key', 'display_name', 'icon_key', 'placeholder']);
        expect($entry)->not->toHaveKey('handle_pattern');
        expect($entry)->not->toHaveKey('host_allowlist');
        expect($entry)->not->toHaveKey('url_path_extractor');
        expect($entry)->not->toHaveKey('url_template');
    }
});

// --- isKnownPlatform() ---

it('recognises known platforms', function () {
    expect(normalizer()->isKnownPlatform('instagram'))->toBeTrue();
    expect(normalizer()->isKnownPlatform('x'))->toBeTrue();
    expect(normalizer()->isKnownPlatform('soundcloud'))->toBeTrue();
});

it('rejects unknown platforms', function () {
    expect(normalizer()->isKnownPlatform('myspace'))->toBeFalse();
    expect(normalizer()->isKnownPlatform(''))->toBeFalse();
});

// --- normalize(): handle path ---

it('normalizes a clean instagram handle', function () {
    $result = normalizer()->normalize('instagram', 'joshhunter', null);

    expect($result['url'])->toBe('https://instagram.com/joshhunter');
    expect($result['handle'])->toBe('joshhunter');
    expect($result['icon_key'])->toBe('instagram');
    expect($result['display_name'])->toBe('Instagram');
    expect($result['platform_key'])->toBe('instagram');
});

it('strips a leading @ from the handle', function () {
    $result = normalizer()->normalize('instagram', '@joshhunter', null);

    expect($result['handle'])->toBe('joshhunter');
    expect($result['url'])->toBe('https://instagram.com/joshhunter');
});

it('trims whitespace around the handle', function () {
    $result = normalizer()->normalize('instagram', '  joshhunter  ', null);

    expect($result['handle'])->toBe('joshhunter');
});

it('rejects a handle containing whitespace in the middle', function () {
    expect(fn () => normalizer()->normalize('instagram', 'josh hunter', null))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a handle with HTML injection attempts', function () {
    expect(fn () => normalizer()->normalize('instagram', '<script>alert(1)</script>', null))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a Cyrillic homoglyph handle', function () {
    // 'joshhunteг' uses Cyrillic 'г' (U+0433) instead of Latin 'r'
    expect(fn () => normalizer()->normalize('instagram', 'joshhunteг', null))
        ->toThrow(InvalidArgumentException::class);
});

it('enforces X handle length limit of 15 characters', function () {
    expect(fn () => normalizer()->normalize('x', 'aaaaaaaaaaaaaaaa', null))
        ->toThrow(InvalidArgumentException::class);

    $result = normalizer()->normalize('x', 'aaaaaaaaaaaaaaa', null);
    expect($result['handle'])->toBe('aaaaaaaaaaaaaaa');
});

it('builds tiktok URL with @ prefix in the path', function () {
    $result = normalizer()->normalize('tiktok', 'joshhunter', null);

    expect($result['url'])->toBe('https://tiktok.com/@joshhunter');
});

it('builds youtube URL with @ prefix in the path', function () {
    $result = normalizer()->normalize('youtube', 'joshhunter', null);

    expect($result['url'])->toBe('https://youtube.com/@joshhunter');
});

it('builds linkedin URL with /in/ prefix', function () {
    $result = normalizer()->normalize('linkedin', 'joshhunter', null);

    expect($result['url'])->toBe('https://linkedin.com/in/joshhunter');
});

// --- normalize(): URL path with extractable handle ---

it('normalizes a clean instagram URL with extractable handle', function () {
    $result = normalizer()->normalize('instagram', null, 'https://instagram.com/joshhunter');

    expect($result['url'])->toBe('https://instagram.com/joshhunter');
    expect($result['handle'])->toBe('joshhunter');
});

it('upgrades http to https on social URLs', function () {
    $result = normalizer()->normalize('instagram', null, 'http://instagram.com/joshhunter');

    expect($result['url'])->toBe('https://instagram.com/joshhunter');
});

it('strips www subdomain when extracting from a URL', function () {
    $result = normalizer()->normalize('instagram', null, 'https://www.instagram.com/joshhunter/');

    expect($result['url'])->toBe('https://instagram.com/joshhunter');
});

it('strips utm query params during URL normalization', function () {
    $result = normalizer()->normalize('instagram', null, 'https://www.instagram.com/joshhunter?utm_source=foo&utm_medium=bar');

    expect($result['url'])->toBe('https://instagram.com/joshhunter');
    expect($result['handle'])->toBe('joshhunter');
});

it('extracts handle from a tiktok URL with @ prefix', function () {
    $result = normalizer()->normalize('tiktok', null, 'https://www.tiktok.com/@joshhunter');

    expect($result['handle'])->toBe('joshhunter');
    expect($result['url'])->toBe('https://tiktok.com/@joshhunter');
});

it('extracts handle from a linkedin /in/ URL and rebuilds the /in/ template unchanged', function () {
    $result = normalizer()->normalize('linkedin', null, 'https://www.linkedin.com/in/joshhunter');

    expect($result['handle'])->toBe('joshhunter');
    expect($result['url'])->toBe('https://linkedin.com/in/joshhunter');
});

it('extracts handle from a linkedin /company/ URL and rebuilds the /company/ template, not /in/ (SEM-1)', function () {
    $result = normalizer()->normalize('linkedin', null, 'https://www.linkedin.com/company/sidest');

    expect($result['handle'])->toBe('sidest');
    expect($result['url'])->toBe('https://linkedin.com/company/sidest');
});

it('a typed linkedin handle still yields the /in/ URL (bare template default)', function () {
    $result = normalizer()->normalize('linkedin', 'sidest', null);

    expect($result['url'])->toBe('https://linkedin.com/in/sidest');
});

it('canonicalises a linkedin /company/ URL: strips www and tracking params (SEM-1 proves (b) would have lost this)', function () {
    $result = normalizer()->normalize('linkedin', null, 'https://www.linkedin.com/company/sidest?trk=abc');

    expect($result['url'])->toBe('https://linkedin.com/company/sidest');
    expect($result['handle'])->toBe('sidest');
});

it('extracts handle from a spotify /user/ URL and rebuilds the /user/ template unchanged', function () {
    $result = normalizer()->normalize('spotify', null, 'https://open.spotify.com/user/joshhunter');

    expect($result['handle'])->toBe('joshhunter');
    expect($result['url'])->toBe('https://open.spotify.com/user/joshhunter');
});

it('extracts handle from a spotify /artist/ URL and rebuilds the /artist/ template, not /user/ (SEM-1)', function () {
    $result = normalizer()->normalize('spotify', null, 'https://open.spotify.com/artist/3TVXtAsR1Inumwj472S9r4');

    expect($result['handle'])->toBe('3TVXtAsR1Inumwj472S9r4');
    expect($result['url'])->toBe('https://open.spotify.com/artist/3TVXtAsR1Inumwj472S9r4');
});

it('accepts a twitter.com URL for the X platform', function () {
    $result = normalizer()->normalize('x', null, 'https://twitter.com/joshhunter');

    expect($result['url'])->toBe('https://x.com/joshhunter');
});

// --- normalize(): URL path with deep links (no handle extractable) ---

it('keeps an instagram post URL as-is when no handle is extractable', function () {
    $result = normalizer()->normalize('instagram', null, 'https://instagram.com/p/abc123def');

    expect($result['url'])->toBe('https://instagram.com/p/abc123def');
    expect($result['handle'])->toBeNull();
});

it('upgrades http to https even on deep links', function () {
    $result = normalizer()->normalize('instagram', null, 'http://instagram.com/p/abc123');

    expect($result['url'])->toBe('https://instagram.com/p/abc123');
});

// --- normalize(): URL path security rejections ---

it('rejects a URL on the wrong host', function () {
    expect(fn () => normalizer()->normalize('instagram', null, 'https://linktr.ee/joshhunter'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a punycode lookalike host', function () {
    // xn--instagram-... would be a homograph attack — host_allowlist is plain ASCII so it fails
    expect(fn () => normalizer()->normalize('instagram', null, 'https://xn--instagram-abc.com/joshhunter'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a malformed URL', function () {
    expect(fn () => normalizer()->normalize('instagram', null, 'not a url at all'))
        ->toThrow(InvalidArgumentException::class);
});

// --- normalize(): error cases ---

it('throws when neither handle nor URL is provided', function () {
    expect(fn () => normalizer()->normalize('instagram', null, null))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => normalizer()->normalize('instagram', '', ''))
        ->toThrow(InvalidArgumentException::class);
});

it('throws on unknown platform key', function () {
    expect(fn () => normalizer()->normalize('myspace', 'joshhunter', null))
        ->toThrow(InvalidArgumentException::class);
});

// --- extractHandleFromUrl() ---

it('extracts handle from a clean URL', function () {
    expect(normalizer()->extractHandleFromUrl('instagram', 'https://instagram.com/joshhunter'))
        ->toBe('joshhunter');
});

it('returns null for a deep-link URL with no extractable handle', function () {
    expect(normalizer()->extractHandleFromUrl('instagram', 'https://instagram.com/p/abc123'))
        ->toBeNull();
});

it('returns null for a wrong-host URL', function () {
    expect(normalizer()->extractHandleFromUrl('instagram', 'https://linktr.ee/joshhunter'))
        ->toBeNull();
});

it('extractHandleFromUrl returns the handle, not the shape, for a linkedin /company/ URL (SEM-1 regression — extractHandleFromUrl has zero production callers, only tests pin it)', function () {
    expect(normalizer()->extractHandleFromUrl('linkedin', 'https://www.linkedin.com/company/sidest'))
        ->toBe('sidest')
        ->not->toBe('company');
});

// --- Subdomain-mode normalization ---

it('normalizes a substack handle by subdomain', function () {
    $result = normalizer()->normalize('substack', 'joshhunter', null);

    expect($result['url'])->toBe('https://joshhunter.substack.com/');
    expect($result['handle'])->toBe('joshhunter');
    expect($result['icon_key'])->toBe('substack');
    expect($result['platform_key'])->toBe('substack');
});

it('strips leading @ in subdomain-mode handle input', function () {
    $result = normalizer()->normalize('substack', '@joshhunter', null);

    expect($result['handle'])->toBe('joshhunter');
    expect($result['url'])->toBe('https://joshhunter.substack.com/');
});

it('extracts the handle from a substack root URL', function () {
    $result = normalizer()->normalize('substack', null, 'https://joshhunter.substack.com/');

    expect($result['url'])->toBe('https://joshhunter.substack.com/');
    expect($result['handle'])->toBe('joshhunter');
});

it('extracts the handle from a bandcamp root URL', function () {
    $result = normalizer()->normalize('bandcamp', null, 'https://somebands.bandcamp.com');

    expect($result['handle'])->toBe('somebands');
    expect($result['url'])->toBe('https://somebands.bandcamp.com/');
});

it('extracts the handle for kajabi (mykajabi.com base)', function () {
    $result = normalizer()->normalize('kajabi', null, 'https://acmecoach.mykajabi.com/');

    expect($result['handle'])->toBe('acmecoach');
    expect($result['url'])->toBe('https://acmecoach.mykajabi.com/');
});

it('falls back to lenient URL storage on subdomain deep-link', function () {
    $result = normalizer()->normalize('substack', null, 'https://joshhunter.substack.com/p/my-post');

    // Deep link, handle not extracted
    expect($result['handle'])->toBeNull();
    // URL is preserved, https forced
    expect($result['url'])->toBe('https://joshhunter.substack.com/p/my-post');
});

it('forces https on http subdomain input', function () {
    $result = normalizer()->normalize('substack', null, 'http://joshhunter.substack.com/');

    expect($result['url'])->toBe('https://joshhunter.substack.com/');
});

it('rejects a labelled-suffix attack: evilsubstack.com must not match substack', function () {
    expect(fn () => normalizer()->normalize('substack', null, 'https://evilsubstack.com/fake'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a wrong-host URL for a subdomain platform', function () {
    expect(fn () => normalizer()->normalize('substack', null, 'https://alice.medium.com/'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a subdomain handle with invalid characters', function () {
    expect(fn () => normalizer()->normalize('substack', 'josh.hunter', null))
        ->toThrow(InvalidArgumentException::class); // dots not allowed per pattern
});

it('rejects a subdomain handle that is too short', function () {
    expect(fn () => normalizer()->normalize('substack', 'ab', null))
        ->toThrow(InvalidArgumentException::class); // min 3 chars
});

it('rejects the bare base domain as handle-less (no subdomain present)', function () {
    expect(fn () => normalizer()->normalize('substack', null, 'https://substack.com/'))
        ->toThrow(InvalidArgumentException::class);
});

it('normalizes a subdomain URL with mixed-case host', function () {
    $result = normalizer()->normalize('substack', null, 'https://JoshHunter.Substack.COM/');

    expect($result['url'])->toBe('https://joshhunter.substack.com/');
    expect($result['handle'])->toBe('joshhunter');
});

it('normalizes a subdomain URL with a trailing-dot FQDN host', function () {
    $result = normalizer()->normalize('substack', null, 'https://joshhunter.substack.com./');

    expect($result['url'])->toBe('https://joshhunter.substack.com/');
    expect($result['handle'])->toBe('joshhunter');
});

// --- Public registry: category exposure ---

it('exposes category in the public registry entries', function () {
    $registry = normalizer()->getPublicRegistry();

    foreach ($registry as $entry) {
        expect($entry)->toHaveKey('category');
        expect($entry['category'])->toBeIn(['social', 'booking', 'education', 'content', 'events', 'streaming', 'other']);
    }
});

it('maps instagram to category=social in the public registry', function () {
    $registry = normalizer()->getPublicRegistry();
    $instagram = collect($registry)->firstWhere('key', 'instagram');

    expect($instagram['category'])->toBe('social');
});

it('maps calendly to category=booking in the public registry', function () {
    $registry = normalizer()->getPublicRegistry();
    $calendly = collect($registry)->firstWhere('key', 'calendly');

    expect($calendly['category'])->toBe('booking');
});

it('still strips internal validation fields including handle_location', function () {
    $registry = normalizer()->getPublicRegistry();

    foreach ($registry as $entry) {
        expect($entry)->not->toHaveKey('handle_pattern');
        expect($entry)->not->toHaveKey('host_allowlist');
        expect($entry)->not->toHaveKey('url_path_extractor');
        expect($entry)->not->toHaveKey('url_template');
        expect($entry)->not->toHaveKey('handle_location');
        expect($entry)->not->toHaveKey('default_category'); // renamed to `category` in output
    }
});

it('strips url_templates (SEM-1 shape map) from the public registry — getPublicRegistry() is a whitelist builder, verify rather than assume', function () {
    $registry = normalizer()->getPublicRegistry();
    $linkedin = collect($registry)->firstWhere('key', 'linkedin');
    $spotify = collect($registry)->firstWhere('key', 'spotify');

    // Sanity: both platforms actually define url_templates in the source config,
    // so this test is exercising a real strip, not a vacuous absence.
    expect(config('partna.social_platforms.linkedin.url_templates'))->not->toBeNull();
    expect(config('partna.social_platforms.spotify.url_templates'))->not->toBeNull();

    expect($linkedin)->not->toHaveKey('url_templates');
    expect($spotify)->not->toHaveKey('url_templates');
    foreach ($registry as $entry) {
        expect($entry)->not->toHaveKey('url_templates');
    }
});

// --- New path-mode platforms: happy-path smoke tests ---

it('normalizes a calendly handle', function () {
    $r = normalizer()->normalize('calendly', 'joshhunter', null);
    expect($r['url'])->toBe('https://calendly.com/joshhunter');
    expect($r['handle'])->toBe('joshhunter');
});

it('normalizes a fresha business slug', function () {
    $r = normalizer()->normalize('fresha', 'acme-hair', null);
    expect($r['url'])->toBe('https://fresha.com/a/acme-hair');
});

it('normalizes a booksy business slug', function () {
    $r = normalizer()->normalize('booksy', '12345_acme-salon', null);
    expect($r['url'])->toBe('https://booksy.com/en-us/12345_acme-salon');
});

it('normalizes a timely business slug', function () {
    $r = normalizer()->normalize('timely', 'acme-hair', null);
    expect($r['url'])->toBe('https://book.gettimely.com/book/acme-hair');
});

it('normalizes a square business slug', function () {
    $r = normalizer()->normalize('square', 'acme-hair', null);
    expect($r['url'])->toBe('https://book.squareup.com/appointments/acme-hair');
});

it('normalizes a stan handle', function () {
    $r = normalizer()->normalize('stan', 'joshhunter', null);
    expect($r['url'])->toBe('https://stan.store/joshhunter');
});

it('normalizes a skool community slug', function () {
    $r = normalizer()->normalize('skool', 'my-community', null);
    expect($r['url'])->toBe('https://skool.com/my-community');
});

it('normalizes an eventbrite organizer slug', function () {
    $r = normalizer()->normalize('eventbrite', 'acme-events', null);
    expect($r['url'])->toBe('https://eventbrite.com/o/acme-events');
});

it('normalizes a humanitix organizer slug', function () {
    $r = normalizer()->normalize('humanitix', 'acme-events', null);
    expect($r['url'])->toBe('https://humanitix.com/host/acme-events');
});

it('normalizes a luma handle', function () {
    $r = normalizer()->normalize('luma', 'joshhunter', null);
    expect($r['url'])->toBe('https://lu.ma/joshhunter');
});

it('normalizes a partiful handle', function () {
    $r = normalizer()->normalize('partiful', 'joshhunter', null);
    expect($r['url'])->toBe('https://partiful.com/u/joshhunter');
});

it('normalizes an apple_podcasts numeric id', function () {
    $r = normalizer()->normalize('apple_podcasts', '1234567890', null);
    expect($r['url'])->toBe('https://podcasts.apple.com/us/podcast/id1234567890');
});

// --- New path-mode platforms: URL extraction + wrong-host rejection ---

it('extracts a handle from a calendly URL', function () {
    $r = normalizer()->normalize('calendly', null, 'https://calendly.com/joshhunter');
    expect($r['handle'])->toBe('joshhunter');
    expect($r['url'])->toBe('https://calendly.com/joshhunter');
});

it('accepts a calendly deep-link (event URL) via lenient fallback', function () {
    $r = normalizer()->normalize('calendly', null, 'https://calendly.com/joshhunter/30min');
    expect($r['handle'])->toBeNull();
    expect($r['url'])->toBe('https://calendly.com/joshhunter/30min');
});

it('rejects a calendly URL with a wrong host', function () {
    expect(fn () => normalizer()->normalize('calendly', null, 'https://calendl-y.com/joshhunter'))
        ->toThrow(InvalidArgumentException::class);
});

it('accepts an eventbrite event URL via lenient fallback', function () {
    $r = normalizer()->normalize('eventbrite', null, 'https://eventbrite.com/e/some-event-123456789');
    expect($r['handle'])->toBeNull();
    expect($r['url'])->toBe('https://eventbrite.com/e/some-event-123456789');
});

it('extracts an apple_podcasts id from a full URL', function () {
    $r = normalizer()->normalize('apple_podcasts', null, 'https://podcasts.apple.com/us/podcast/my-show/id1234567890');
    expect($r['handle'])->toBe('1234567890');
    expect($r['url'])->toBe('https://podcasts.apple.com/us/podcast/id1234567890');
});

it('forces https on an http calendly URL', function () {
    $r = normalizer()->normalize('calendly', null, 'http://calendly.com/joshhunter');
    expect($r['url'])->toBe('https://calendly.com/joshhunter');
});

it('rejects an invalid eventbrite handle with special characters', function () {
    expect(fn () => normalizer()->normalize('eventbrite', 'has spaces', null))
        ->toThrow(InvalidArgumentException::class);
});

it('extracts the handle for circle (circle.so base)', function () {
    $result = normalizer()->normalize('circle', null, 'https://acmecommunity.circle.so/');

    expect($result['handle'])->toBe('acmecommunity');
    expect($result['url'])->toBe('https://acmecommunity.circle.so/');
});
