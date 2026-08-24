<?php

use App\Services\Platforms\LinkInBioInlinePayloadReader;

// liinks.co ships ~360 KB with ZERO <a> anchors — every link is drawn by React —
// while inlining the whole link list as JSON in the same bytes. Shape below is
// the real 2026-08-19 payload for liinks.co/liinks, trimmed to the keys we read.

function liinksBody(array $links): string
{
    $context = json_encode(['BASE_DOMAIN' => 'liinks.co', 'USER_DATA' => [
        'user' => ['id' => '5e50647fc6ee73586fd46df6'],
        'links' => $links,
    ]]);

    return '<!doctype html><html><body><div id="root"></div>'
        .'<script>window.CONTEXT = '.$context.';</script></body></html>';
}

function liinksLink(string $target, array $overrides = []): array
{
    return array_merge([
        'id' => '5e5065d6c6ee73586fd46dfc',
        'linkType' => 'LINK',
        'linkLayout' => 'BUTTON',
        'title' => 'A link',
        'target' => $target,
        'isHidden' => false,
        'isDeleted' => false,
    ], $overrides);
}

it('returns the destination links inlined in a client-rendered liinks.co page', function () {
    $links = app(LinkInBioInlinePayloadReader::class)->read('https://www.liinks.co/creator', liinksBody([
        liinksLink('https://shop.example.com'),
        liinksLink('https://www.instagram.com/creator'),
    ]));

    expect($links)->toBe(['https://shop.example.com', 'https://www.instagram.com/creator']);
});

it('omits links the owner hid or deleted', function () {
    // Same reasoning as linkin.bio's `enabled: false`: these are invisible to
    // every human visitor, so publishing them re-posts links that were taken down.
    $links = app(LinkInBioInlinePayloadReader::class)->read('https://liinks.co/creator', liinksBody([
        liinksLink('https://hidden.example.com', ['isHidden' => true]),
        liinksLink('https://deleted.example.com', ['isDeleted' => true]),
        liinksLink('https://live.example.com'),
    ]));

    expect($links)->toBe(['https://live.example.com']);
});

it('ignores page furniture that carries no destination', function () {
    // DIVIDER is a section heading; INSTAGRAM is an embedded post grid that
    // duplicates the IG connection the harvest already makes.
    $links = app(LinkInBioInlinePayloadReader::class)->read('https://liinks.co/creator', liinksBody([
        array_merge(liinksLink(''), ['linkType' => 'DIVIDER', 'title' => 'Featured']),
        array_merge(liinksLink('https://www.instagram.com/p/ABC/'), ['linkType' => 'INSTAGRAM']),
        liinksLink('https://live.example.com'),
    ]));

    expect($links)->toBe(['https://live.example.com']);
});

it('refuses a scheme-less target rather than inventing one', function () {
    // Liinks stores some targets as bare "liinks.co/foo" — relative to their own
    // host, so the importer's chrome rule would drop them anyway.
    $links = app(LinkInBioInlinePayloadReader::class)->read('https://liinks.co/creator', liinksBody([
        liinksLink('liinks.co/dora.kamau'),
        liinksLink('https://live.example.com'),
    ]));

    expect($links)->toBe(['https://live.example.com']);
});

it('declines a host it does not read, so the anchor harvest still runs', function () {
    // null means "not mine", which is NOT the same as "no links".
    expect(app(LinkInBioInlinePayloadReader::class)->read('https://linktr.ee/someone', liinksBody([
        liinksLink('https://shop.example.com'),
    ])))->toBeNull();
});

it('declines when the shell carries no payload at all', function () {
    expect(app(LinkInBioInlinePayloadReader::class)->read('https://liinks.co/creator', '<html><body></body></html>'))
        ->toBeNull();
});

it('declines when the payload is not the shape it knows', function () {
    // Liinks can rev their shell without telling us. A silent shape change must
    // fall back to the anchor harvest + zero-yield floor, not delete the links.
    $body = '<html><script>window.CONTEXT = {"USER_DATA":{"user":{"id":"x"}}};</script></html>';

    expect(app(LinkInBioInlinePayloadReader::class)->read('https://liinks.co/creator', $body))->toBeNull();
});

it('is not fooled into stopping early by a brace inside a link title', function () {
    // The payload is one line of minified JSON; a `}` typed into a title is
    // JSON-escaped and must not terminate the match before the links array.
    $links = app(LinkInBioInlinePayloadReader::class)->read('https://liinks.co/creator', liinksBody([
        liinksLink('https://first.example.com', ['title' => 'Weird }; title']),
        liinksLink('https://second.example.com'),
    ]));

    expect($links)->toBe(['https://first.example.com', 'https://second.example.com']);
});
