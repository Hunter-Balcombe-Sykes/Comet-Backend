<?php

// TEST-44: XXE / entity-expansion regression cover for the ONE parser that
// turns an untrusted third-party XML body into ingest records. Both YouTube
// connectors share YoutubeFeed::parse(), so this file is the only place the
// posture documented in its docblock is actually enforced.
//
// The guarantees come from libxml itself (no external-entity loading since
// >= 2.9, plus the max-amplification guard) and from LIBXML_NONET — NOT from
// omitting LIBXML_NOENT. Internal-DTD entities are substituted regardless, so
// there is deliberately no test asserting otherwise: it would be red on
// arrival and would misrepresent where the defence lives.

use App\Ingest\Support\YoutubeFeed;
use Tests\TestCase;

// Pure static parser, no DB — but base_path() below needs the container, so
// this file opts into the app the same way ProjectionTest does.
uses(TestCase::class)->in(__FILE__);

/**
 * A minimal but REALISTIC uploads-feed body with an injectable internal DTD
 * subset. The `xmlns:media` declaration and media:group are not decoration:
 * without them mapEntry() dereferences null at the thumbnail lookup (a
 * separate latent bug, out of scope here), which would turn every case below
 * into a crash instead of an XXE assertion. Real YouTube feeds always declare
 * it, so this matches the wire.
 */
function youtubeFeedXxeFeed(string $internalSubset, string $title): string
{
    $doctype = $internalSubset === '' ? '<!DOCTYPE feed>' : "<!DOCTYPE feed [ {$internalSubset} ]>";

    return <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    {$doctype}
    <feed xmlns="http://www.w3.org/2005/Atom" xmlns:yt="http://www.youtube.com/xml/schemas/2015" xmlns:media="http://search.yahoo.com/mrss/">
      <title>Some Channel</title>
      <entry>
        <yt:videoId>dQw4w9WgXcQ</yt:videoId>
        <title>{$title}</title>
        <link rel="alternate" href="https://www.youtube.com/watch?v=dQw4w9WgXcQ"/>
        <published>2026-07-01T10:00:00+00:00</published>
        <media:group>
          <media:thumbnail url="https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg" width="480" height="360"/>
        </media:group>
      </entry>
    </feed>
    XML;
}

it('never resolves an external entity — a SYSTEM file reference is dropped, not read', function () {
    // composer.json, not /etc/passwd: it is guaranteed to exist and be readable
    // in every lane (a missing/unreadable target would make this vacuously
    // green), and it carries a stable canary string.
    $canaryFile = base_path('composer.json');
    expect(file_get_contents($canaryFile))->toContain('laravel/framework');

    $records = YoutubeFeed::parse(youtubeFeedXxeFeed(
        '<!ENTITY xxe SYSTEM "file://'.$canaryFile.'">',
        'Episode 12 &xxe;',
    ));

    // NON-VACUITY GUARD, and the most important assertion in this file: the
    // entry around the entity reference is well-formed and MUST come back. If a
    // future change made this payload fail to parse for an unrelated reason,
    // parse() would return null and the canary assertion below would pass while
    // proving nothing.
    expect($records)->toHaveCount(1)
        ->and($records[0]['id'])->toBe('dQw4w9WgXcQ')
        ->and($records[0]['title'])->toBe('Episode 12');

    // Asserted over the WHOLE serialised result, not one field: that is what
    // keeps this catching disclosure through any field a later refactor adds.
    //
    // JSON_UNESCAPED_SLASHES is load-bearing, not tidiness. Plain json_encode()
    // writes "laravel\/framework", so a slash-bearing canary can NEVER be found
    // in its output and the assertion below would silently pass forever no
    // matter how much of the file leaked. The expect() on the leaky control
    // right after is the standing proof this check can still go red — delete
    // either line and you are back to a vacuous test.
    $serialised = json_encode($records, JSON_UNESCAPED_SLASHES);
    expect($serialised)->not->toContain('laravel/framework');
    expect(json_encode([['title' => 'leaked laravel/framework']], JSON_UNESCAPED_SLASHES))
        ->toContain('laravel/framework');
});

it('returns null rather than expanding an entity bomb', function () {
    // Billion laughs, 10 levels x 10. libxml's max-amplification guard makes
    // the load fail outright, which parse() reports as null. The connectors'
    // contract for null is "feed unavailable, leave the last good records
    // alone" — never "an empty feed", which would look like a wiped channel.
    $subset = '<!ENTITY lol0 "'.str_repeat('a', 50).'">';
    for ($i = 1; $i <= 10; $i++) {
        $subset .= '<!ENTITY lol'.$i.' "'.str_repeat('&lol'.($i - 1).';', 10).'">';
    }

    expect(YoutubeFeed::parse(youtubeFeedXxeFeed($subset, '&lol10;')))->toBeNull();
});

it('still parses a well-formed feed that declares a DTD', function () {
    // The control. Without it, both tests above would also pass if parse() were
    // changed to `return null` unconditionally — a DOCTYPE alone must not be
    // treated as hostile, because rejecting it would be a silent ingest outage.
    $records = YoutubeFeed::parse(youtubeFeedXxeFeed('', 'Episode 12'));

    expect($records)->toHaveCount(1)
        ->and($records[0]['id'])->toBe('dQw4w9WgXcQ')
        ->and($records[0]['title'])->toBe('Episode 12')
        ->and($records[0]['url'])->toBe('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
});
