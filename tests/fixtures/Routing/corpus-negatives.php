<?php

// Hand-written half of the routing corpus (plan §2/§22): URLs that must NEVER
// connect. The positive half is generated from the catalog itself
// (`php artisan routing:corpus` → corpus-generated.php); this half cannot be
// generated, because it encodes what an attacker would try and what real
// pastes look like when they go wrong.
//
// `reason` is documentation, not an assertion — the test asserts only that no
// surface is placed, since several of these are refused by more than one
// mechanism (a spoofed host is both unknown-domain AND fails every pattern).

$brands = [
    'opentable', 'thefork', 'eventbrite', 'deliveroo', 'fresha', 'booksy',
    'square', 'instagram', 'tiktok', 'spotify', 'youtube', 'twitch',
    'ubereats', 'doordash', 'quandoo', 'treatwell', 'resy', 'tock',
    'ticketmaster', 'vagaro', 'humanitix', 'soundcloud', 'bandcamp',
    'pinterest', 'linkedin', 'facebook', 'medium', 'substack', 'skool',
    'menulog', 'nowbookit', 'resdiary',
];

$cases = [];

// ── Host spoofing: the exact class the old `brand\.[a-z.]+$` regexes admitted.
foreach ($brands as $brand) {
    $cases[] = ['url' => "https://{$brand}.evil.com/restaurant/profile/12345", 'reason' => 'spoofed-host'];
    $cases[] = ['url' => "https://www.{$brand}.attacker.io/a/thing", 'reason' => 'spoofed-host'];
}

// ── Lookalike domains: someone else's real domain that reads like a brand.
foreach ([
    'https://opentab1e.com/restaurant/profile/1',
    'https://0pentable.com.au/restaurant/profile/1',
    'https://fresha-book.com/a/salon',
    'https://instagram-login.com/someone',
    'https://tiktok.com.evil.co/@someone',
    'https://youtube-videos.net/@channel',
    'https://rnedium.com/@writer',
    'https://spotifly.com/artist/123',
    'https://faceb00k.com/someprofile',
    'https://1inkedin.com/in/someone',
    'https://twitch-tv.net/streamer',
    'https://uber-eats-order.com/store/x',
] as $url) {
    $cases[] = ['url' => $url, 'reason' => 'lookalike-host'];
}

// ── IDN confusables: mixed-script hosts that render as a latin brand.
// xn--pple-43d = "аpple" (Cyrillic а). xn--80ak6aa92e = "аррӏе" (all Cyrillic).
foreach ([
    ['https://xn--pple-43d.com/x', 'Cyrillic а + Latin pple'],
    ['https://xn--pple-43d.com/restaurant/profile/1', 'Cyrillic а, deep path'],
] as [$url, $note]) {
    $cases[] = ['url' => $url, 'reason' => 'idn-confusable', 'note' => $note];
}

// ── Userinfo tricks: the real host is after the @.
foreach ([
    'https://www.opentable.com.au@evil.com/restaurant/profile/12345',
    'https://instagram.com:pass@phish.io/someone',
    'https://www.fresha.com@attacker.net/a/salon',
    'https://x.com%40evil.com/someuser',
] as $url) {
    $cases[] = ['url' => $url, 'reason' => 'userinfo-trick'];
}

// ── Brand names embedded in a path or query on someone else's domain.
foreach ([
    'https://evil.com/opentable.com.au/restaurant/profile/1',
    'https://phish.io/?next=https://instagram.com/someone',
    'https://evil.com/#instagram.com/someone',
    'https://attacker.net/redirect?to=https%3A%2F%2Fwww.fresha.com%2Fa%2Fsalon',
    'https://evil.com/www.opentable.com.au',
] as $url) {
    $cases[] = ['url' => $url, 'reason' => 'path-embedded-brand'];
}

// ── Reserved paths: real brand host, but a path that is never a profile.
foreach ([
    ['https://www.facebook.com/sharer/sharer.php?u=https://example.com', 'share widget'],
    ['https://www.facebook.com/marketplace/item/123456', 'marketplace listing (named in plan §2)'],
    ['https://x.com/intent/tweet?text=hi', 'intent widget'],
    ['https://twitter.com/intent/follow?screen_name=someone', 'intent widget'],
    ['https://x.com/i/flow/login', 'reserved /i/ namespace'],
    ['https://www.instagram.com/p/ABC123XYZ/', 'a post, not a profile'],
    ['https://www.instagram.com/reel/ABC123XYZ/', 'a reel, not a profile'],
    ['https://www.instagram.com/accounts/login/', 'auth page'],
    ['https://www.instagram.com/explore/tags/food/', 'discovery page'],
    ['https://www.opentable.com.au/r/some-restaurant-slug', 'slug link carries no rid'],
    ['https://www.tiktok.com/discover/food', 'discovery page'],
    ['https://www.youtube.com/results?search_query=x', 'search results'],
] as [$url, $note]) {
    $cases[] = ['url' => $url, 'reason' => 'reserved-path', 'note' => $note];
}

// ── Bare domains: recognised host, nothing identified.
foreach ([
    'https://instagram.com', 'https://www.instagram.com/', 'https://x.com/',
    'https://www.tiktok.com', 'https://www.facebook.com/', 'https://youtube.com',
    'https://www.opentable.com.au/', 'https://www.fresha.com', 'https://soundcloud.com/',
    'https://open.spotify.com/', 'https://www.pinterest.com/', 'https://medium.com/',
] as $url) {
    $cases[] = ['url' => $url, 'reason' => 'bare-domain'];
}

// ── Own infrastructure: the platform must never fetch or connect itself.
foreach ([
    'https://partna.au/',
    'https://dev-api.partna.au/api/internal/health',
    'https://someone.partna.au/',
    'https://api.partna.au/api/user/me',
    'https://abcdef.supabase.co/rest/v1/users',
    'https://env-name.laravel.cloud/x',
    'https://bucket.r2.cloudflarestorage.com/object',
    'https://pub-abc123.r2.dev/file.png',
    'https://worker.partna.workers.dev/',
] as $url) {
    $cases[] = ['url' => $url, 'reason' => 'own-infra'];
}

// ── Non-http schemes.
foreach ([
    'javascript:alert(document.cookie)',
    'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
    'mailto:someone@example.com',
    'tel:+61400000000',
    'ftp://files.example.com/pub',
    'file:///etc/passwd',
    'chrome://settings',
    'intent://scan/#Intent;scheme=zxing;end',
] as $url) {
    $cases[] = ['url' => $url, 'reason' => 'non-http-scheme'];
}

// ── Shorteners: recorded, never auto-connected before expansion.
foreach ([
    'https://bit.ly/3xYzAbc', 'https://tinyurl.com/abcd1234', 'https://t.co/AbCdEf',
    'https://lnkd.in/abcdef', 'https://rebrand.ly/promo', 'https://ow.ly/abc123',
    'https://linktr.ee/someone', 'https://is.gd/abcdef',
] as $url) {
    $cases[] = ['url' => $url, 'reason' => 'shortener'];
}

// ── Ordinary businesses: the common case, and it must stay quiet.
foreach ([
    'https://joesplumbing.com.au/', 'https://sarahsalon.co.nz/services',
    'https://thecornercafe.net/menu', 'https://mikebuilds.com.au/about',
    'https://claires-cakes.com/order', 'https://northsidedental.com.au/',
    'https://example.com/', 'https://www.wikipedia.org/wiki/Coffee',
    'https://news.ycombinator.com/item?id=1', 'https://docs.google.com/document/d/abc/edit',
    'https://someones-blog.wordpress.com/2026/07/post',
    'https://drive.google.com/file/d/abc/view',
    'https://www.gov.au/', 'https://mail.google.com/mail/u/0/',
    'https://calendar.app/booking',
] as $url) {
    $cases[] = ['url' => $url, 'reason' => 'unknown-domain'];
}

// ── Malformed / hostile input.
foreach ([
    ['', 'empty'],
    ['   ', 'whitespace only'],
    ['https://', 'scheme with no host'],
    ['https://..', 'dots for a host'],
    ['://nohost/x', 'no scheme'],
    ['http://.com/x', 'leading-dot host'],
    ['https://[not-an-ip]/x', 'bracketed non-IP'],
    ['https://192.168.1.10/admin', 'private IP literal'],
    ['https://127.0.0.1:8000/x', 'loopback literal'],
    ['https://169.254.169.254/latest/meta-data/', 'cloud metadata endpoint'],
    ['https://com.au/', 'host IS a public suffix'],
    ['not a url at all', 'prose'],
] as [$url, $note]) {
    $cases[] = ['url' => $url, 'reason' => 'malformed', 'note' => $note];
}

$cases[] = ['url' => 'https://example.com/'.str_repeat('a', 3000), 'reason' => 'malformed', 'note' => 'over the length cap'];

return $cases;
