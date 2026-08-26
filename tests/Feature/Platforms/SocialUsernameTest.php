<?php

use App\Services\Platforms\LinkRouter;

// socialUsername() — the identity a routed/seeded social link stores in its
// payload (plan 03, 2026-08-27). Found live: the hand-kept pattern list knew
// four platforms and silently wrote username:'' for every other social —
// a pasted instagram profile seeded with no handle at all. The method now
// defers to the platform's own catalog-wired UrlConnect normalizer first
// (a pure parse), keeps facebook's normalizer special-case, and carries an
// instagram fallback pattern (its connect is bespoke, no UrlConnect).

function socialUsernameOf(string $platform, string $url): string
{
    $m = new ReflectionMethod(LinkRouter::class, 'socialUsername');

    return (string) $m->invoke(app(LinkRouter::class), $platform, $url);
}

it('parses the handle for every catalog-normalized social, not just the old four', function (string $platform, string $url, string $expected) {
    expect(socialUsernameOf($platform, $url))->toBe($expected);
})->with([
    // The live find: instagram (bespoke connect → fallback pattern here).
    'instagram profile' => ['instagram', 'https://www.instagram.com/stalicoffee/', 'stalicoffee'],
    'instagram with query junk' => ['instagram', 'https://instagram.com/stalicoffee?igsh=abc123', 'stalicoffee'],
    // Reserved segments must not read as handles.
    'instagram post is not a handle' => ['instagram', 'https://www.instagram.com/p/DAbCdEf/', ''],
    'instagram reel is not a handle' => ['instagram', 'https://www.instagram.com/reel/DAbCdEf/', ''],
    // Platforms whose UrlConnect normalizer now answers (previously '').
    'threads' => ['threads', 'https://www.threads.net/@sallybakes', 'sallybakes'],
    'reddit user' => ['reddit', 'https://www.reddit.com/user/spez/', 'spez'],
    'twitch channel' => ['twitch', 'https://www.twitch.tv/cohhcarnage', 'cohhcarnage'],
    // The old four still parse (tiktok/x/linkedin via normalizer or pattern).
    'tiktok' => ['tiktok', 'https://www.tiktok.com/@thebanjobarber', 'thebanjobarber'],
    'x' => ['x', 'https://x.com/jack', 'jack'],
    'linkedin company' => ['linkedin', 'https://www.linkedin.com/company/acme-studio', 'acme-studio'],
]);

it('still returns empty for a platform with no parse rather than guessing', function () {
    expect(socialUsernameOf('website', 'https://example.com/whatever'))->toBe('');
});
