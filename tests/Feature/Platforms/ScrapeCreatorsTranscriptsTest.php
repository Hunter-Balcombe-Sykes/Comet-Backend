<?php

use App\Services\Cache\ScrapeCreatorsBudget;
use App\Services\Platforms\ScrapeCreators\TranscriptNormalizer;
use App\Services\Platforms\TranscriptFetcher;
use Illuminate\Support\Facades\Http;

// Item 11e (2026-09-01): video → text for the AI-enrichment layer, the plan's
// most speculative item — shipped behind its own cap with NO consumer wired.
// Pinned against RECORDED payloads captured 2026-09-01 (the vendor's own
// documented public example media, so the captures are stable and re-checkable):
//   scrapecreators-tiktok-video-transcript.json     stoolpresidente pizza
//                                                   review — WEBVTT cue sheet
//   scrapecreators-youtube-video-transcript.json    bjVIDXPP7Uk MTB vlog —
//                                                   338 segments + only_text
//                                                   + language + captionTracks
//   scrapecreators-facebook-post-transcript.json    protein-dessert reel —
//                                                   SRT cues (docs show plain
//                                                   text; both must normalize)
//   scrapecreators-instagram-media-transcript.json  bunzel reel — one-slide
//                                                   transcripts array
// Same two properties every ScrapeCreators suite pins: the normalized contract
// is exact ({text, language, source} — no credits_*/cue timing/captionTracks
// leak), and every vendor outcome short of usable speech reads as a miss with
// the Item 8 budget mechanics (release on transport-null, slot stays spent on
// billed husks).

function scTranscriptFixture(string $name): array
{
    return json_decode(
        file_get_contents(base_path("tests/fixtures/recorded/scrapecreators-{$name}.json")),
        true
    );
}

function scTranscripts(): TranscriptFetcher
{
    return app(TranscriptFetcher::class);
}

beforeEach(function () {
    config()->set('services.scrapecreators.key', 'test-key');
    config()->set('partna.limits.scrapecreators.global_daily_cap', 100);
    config()->set('partna.limits.scrapecreators.sources.transcripts', 100);
});

// ── The four recorded dialects → one contract ───────────────────────────────

it('flattens the recorded TikTok WEBVTT cue sheet into prose', function () {
    Http::fake(['api.scrapecreators.com/*' => Http::response(scTranscriptFixture('tiktok-video-transcript'))]);

    $t = scTranscripts()->fetch('tiktok', 'https://www.tiktok.com/@stoolpresidente/video/7499229683859426602');

    expect($t)->not->toBeNull()
        ->and($t['text'])->toStartWith('Alright, pizza review time.')
        ->and($t['text'])->toContain('This is an outrageous pizza.')
        // Cue skeleton fully discarded — header, timings, blank separators.
        ->and($t['text'])->not->toContain('WEBVTT')
        ->and($t['text'])->not->toContain('-->')
        ->and($t['language'])->toBeNull()
        ->and($t['source'])->toBe('tiktok');

    // Synthesized, never spread: exactly these keys, so credits_*, the vendor
    // id/url echo, and cue timing can never ride into a persisted payload.
    expect(array_keys($t))->toBe(['text', 'language', 'source']);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/tiktok/video/transcript')
        && $request['url'] === 'https://www.tiktok.com/@stoolpresidente/video/7499229683859426602');
});

it('reads the recorded YouTube payload: only_text collapsed, caption language kept', function () {
    Http::fake(['api.scrapecreators.com/*' => Http::response(scTranscriptFixture('youtube-video-transcript'))]);

    $t = scTranscripts()->fetch('youtube', 'https://www.youtube.com/watch?v=bjVIDXPP7Uk');

    expect($t['text'])->toStartWith('Welcome back to the hell farm')
        // transcript_only_text double-pads every word and line-breaks every
        // segment — the contract is flowing prose, single-spaced.
        ->and($t['text'])->not->toContain('  ')
        ->and($t['text'])->not->toContain("\n")
        ->and($t['language'])->toBe('English')
        ->and($t['source'])->toBe('youtube')
        ->and(array_keys($t))->toBe(['text', 'language', 'source']);

    // The vendor-side cache makes a repeat lookup free — transcripts of a
    // given video never change. (TikTok's route lacks the parameter.)
    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/youtube/video/transcript')
        && $request['cache_max_age'] === '30d');
});

it('flattens the recorded Facebook SRT cue sheet — numbered cues and timings dropped, sentences rejoined', function () {
    Http::fake(['api.scrapecreators.com/*' => Http::response(scTranscriptFixture('facebook-post-transcript'))]);

    $t = scTranscripts()->fetch('facebook', 'https://www.facebook.com/reel/1535656380759655');

    // Cue wrapping split this mid-clause ("Throw one\nripe banana") — the
    // enrichment layer needs it back as one sentence.
    expect($t['text'])->toContain('Throw one ripe banana into a bowl')
        ->and($t['text'])->toContain('27 grams of protein.')
        ->and($t['text'])->not->toContain('-->')
        ->and($t['text'])->not->toStartWith('1 ')
        ->and($t['language'])->toBeNull()
        ->and($t['source'])->toBe('facebook');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/facebook/post/transcript'));
});

it('reads the recorded Instagram transcripts array — the AI-generated slide text', function () {
    Http::fake(['api.scrapecreators.com/*' => Http::response(scTranscriptFixture('instagram-media-transcript'))]);

    $t = scTranscripts()->fetch('instagram', 'https://www.instagram.com/reel/DHsD6HGqJhp');

    expect($t['text'])->toStartWith("Let's fry up the perfect")
        ->and($t['text'])->toContain('we make it the crispiest')
        ->and($t['source'])->toBe('instagram')
        ->and(array_keys($t))->toBe(['text', 'language', 'source']);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v2/instagram/media/transcript'));
});

// ── Normalizer dialects beyond the recordings ───────────────────────────────

it('joins Instagram carousel slides as paragraphs and skips speechless slides', function () {
    $t = (new TranscriptNormalizer)->normalize('instagram', ['success' => true, 'transcripts' => [
        ['id' => '1', 'shortcode' => 'AAA', 'text' => 'First slide speech.'],
        // "null when no speech is detected" — per slide, not per post.
        ['id' => '2', 'shortcode' => 'BBB', 'text' => null],
        ['id' => '3', 'shortcode' => 'CCC', 'text' => '  Third slide speech.  '],
    ]]);

    expect($t['text'])->toBe("First slide speech.\n\nThird slide speech.");
});

it('accepts the plain-text Facebook dialect the vendor documents alongside the recorded SRT one', function () {
    $t = (new TranscriptNormalizer)->normalize('facebook', [
        'success' => true, 'transcript' => "We're fishing\nbite x2\nBest bait\non this pole",
    ]);

    expect($t['text'])->toBe("We're fishing bite x2 Best bait on this pole");
});

it('reads empty, whitespace-only, and wrong-typed transcripts as misses, never as answers', function () {
    $n = new TranscriptNormalizer;

    expect($n->normalize('tiktok', ['success' => true, 'transcript' => "WEBVTT\n\n"]))->toBeNull()
        ->and($n->normalize('facebook', ['success' => true, 'transcript' => "  \n  "]))->toBeNull()
        // YouTube's no-caption-track answer: the fields ride as null.
        ->and($n->normalize('youtube', ['success' => true, 'transcript' => null, 'transcript_only_text' => null]))->toBeNull()
        ->and($n->normalize('instagram', ['success' => true, 'transcripts' => [['id' => '1', 'text' => null]]]))->toBeNull()
        ->and($n->normalize('tiktok', ['success' => true, 'transcript' => ['not' => 'a-string']]))->toBeNull()
        ->and($n->normalize('vimeo', ['success' => true, 'transcript' => 'Real speech']))->toBeNull();
});

// ── Budget + lane mechanics ─────────────────────────────────────────────────

it('refuses a NotFound husk as a vendor miss and keeps the billed slot spent', function () {
    config()->set('partna.limits.scrapecreators.sources.transcripts', 1);
    // The NotFound quirk: success:true, a credit charged, no transcript keys.
    Http::fake(['api.scrapecreators.com/*' => Http::response([
        'success' => true, 'credits_remaining' => 9999, 'credits_charged' => 1,
    ])]);

    expect(scTranscripts()->fetch('youtube', 'https://www.youtube.com/watch?v=gone'))->toBeNull();
    expect(app(ScrapeCreatorsBudget::class)->tryClaim('transcripts'))->toBeFalse();
});

it('returns null on a vendor 5xx and releases the budget slot', function () {
    config()->set('partna.limits.scrapecreators.sources.transcripts', 1);
    Http::fake(['api.scrapecreators.com/*' => Http::response('upstream sad', 502)]);

    expect(scTranscripts()->fetch('tiktok', 'https://www.tiktok.com/@x/video/1'))->toBeNull();
    // Transport-level null handed the day's only slot back.
    expect(app(ScrapeCreatorsBudget::class)->tryClaim('transcripts'))->toBeTrue();
});

it('skips the lane entirely when no key is configured', function () {
    config()->set('services.scrapecreators.key', null);
    Http::fake();

    expect(scTranscripts()->fetch('youtube', 'https://www.youtube.com/watch?v=bjVIDXPP7Uk'))->toBeNull();
    Http::assertNothingSent();
});

it('skips the lane when the transcripts budget is exhausted — dormant until the cap lands', function () {
    // An absent partna.limits.scrapecreators.sources.transcripts entry reads
    // 0: this is also the shipped state until the central config pass.
    config()->set('partna.limits.scrapecreators.sources.transcripts', 0);
    Http::fake();

    expect(scTranscripts()->fetch('tiktok', 'https://www.tiktok.com/@x/video/1'))->toBeNull();
    Http::assertNothingSent();
});

it('refuses unknown platforms and off-platform or crafted URLs before spending anything', function () {
    config()->set('partna.limits.scrapecreators.sources.transcripts', 1);
    Http::fake();

    expect(scTranscripts()->fetch('vimeo', 'https://vimeo.com/12345'))->toBeNull()
        ->and(scTranscripts()->fetch('tiktok', 'https://example.com/video/1'))->toBeNull()
        ->and(scTranscripts()->fetch('tiktok', 'not a url'))->toBeNull()
        // Suffix spoof: the allowlist matches the registrable host, never a substring.
        ->and(scTranscripts()->fetch('tiktok', 'https://tiktok.com.evil.example/video/1'))->toBeNull()
        ->and(scTranscripts()->fetch('youtube', 'ftp://youtube.com/watch?v=x'))->toBeNull();
    Http::assertNothingSent();
    // No claim was burned on any refusal.
    expect(app(ScrapeCreatorsBudget::class)->tryClaim('transcripts'))->toBeTrue();
});

it('accepts each platform host family it exists for — www, mobile, and short hosts', function () {
    Http::fake(['api.scrapecreators.com/*' => Http::response(scTranscriptFixture('youtube-video-transcript'))]);

    expect(scTranscripts()->fetch('youtube', 'https://youtu.be/bjVIDXPP7Uk'))->not->toBeNull()
        ->and(scTranscripts()->fetch('youtube', 'https://m.youtube.com/watch?v=bjVIDXPP7Uk'))->not->toBeNull();
});
