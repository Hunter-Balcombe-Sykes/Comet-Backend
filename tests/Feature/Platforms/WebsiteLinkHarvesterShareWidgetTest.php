<?php

use App\Services\Platforms\WebsiteLinkHarvester;

// Every one of these is a "post this page to X" button lifted verbatim from
// https://clk.bio/TheMetaPunter (2026-08-24). Classifying one as a profile
// connects a share endpoint as the owner's account.
it('refuses to read a share widget as somebody\'s profile', function (string $url) {
    expect(app(WebsiteLinkHarvester::class)->classify($url))->toBeNull();
})->with([
    'linkedin' => 'https://www.linkedin.com/sharing/share-offsite/?url=https%3A%2F%2Fclk.bio%2FTheMetaPunter',
    'reddit' => 'https://www.reddit.com/submit?url=https%3A%2F%2Fclk.bio%2FTheMetaPunter&title=Check',
    'facebook' => 'https://www.facebook.com/sharer.php?u=https%3A%2F%2Fclk.bio%2FTheMetaPunter',
    'twitter' => 'https://twitter.com/intent/tweet?text=Check',
    'whatsapp' => 'https://wa.me/?text=Check',
    // Not on the clk.bio page — found while regression-checking every
    // social host the guard now covers. Telegram's share endpoint
    // classified as a real channel before this change.
    'telegram' => 'https://t.me/share/url?url=https%3A%2F%2Facme.test',
]);

// The guard must not swallow the real accounts sitting next to those buttons
// on the same page.
it('still reads a real profile on the same hosts', function (string $url, string $platform) {
    $classified = app(WebsiteLinkHarvester::class)->classify($url);

    expect($classified)->not->toBeNull()
        ->and($classified['platform'])->toBe($platform);
})->with([
    ['https://www.linkedin.com/in/joe-osborne', 'linkedin'],
    ['https://www.linkedin.com/company/partna', 'linkedin'],
    ['https://www.reddit.com/user/themetapunter', 'reddit'],
    ['https://www.instagram.com/themetapunter', 'instagram'],
    ['https://www.tiktok.com/@joe__o', 'tiktok'],
]);

// The guard's real consumer. harvest()/harvestHtml() feed
// GoogleBusinessAutoSync::seed(), so before this fix a "share this page"
// button on ANY business website was seeded as that business's own account —
// the bio-link lane just happened to be where it was noticed.
it('does not seed a share widget as the business\'s social account', function () {
    $harvested = app(WebsiteLinkHarvester::class)->harvestHtml('<html><body>
        <a href="https://www.linkedin.com/sharing/share-offsite/?url=https%3A%2F%2Facme.test">Share on LinkedIn</a>
        <a href="https://www.reddit.com/submit?url=https%3A%2F%2Facme.test">Share on Reddit</a>
    </body></html>', 'https://acme.test/');

    expect($harvested)->toBe([]);
});

it('still seeds the real accounts alongside those buttons', function () {
    $harvested = app(WebsiteLinkHarvester::class)->harvestHtml('<html><body>
        <a href="https://www.linkedin.com/sharing/share-offsite/?url=https%3A%2F%2Facme.test">Share</a>
        <a href="https://www.linkedin.com/company/acme">Our LinkedIn</a>
    </body></html>', 'https://acme.test/');

    expect($harvested['socials']['linkedin'] ?? null)->toBe('https://www.linkedin.com/company/acme');
});
