<?php

// tests/Unit/Pools/ItemLinkPlatformResolutionTest.php

use App\Site\Pools\ItemLinkRules;

/**
 * platformForUrl() matches by host SUFFIX, so one brand's domain can swallow
 * another brand that lives on a subdomain of it. With first-declared-wins
 * iteration, `music.youtube.com` answered 'youtube' — youtube-music sat in
 * ROSTER['listen'] but was unreachable from any URL, so a manually-added
 * YouTube Music link was badged YouTube on the public wire and PoolResolver's
 * per-platform dedupe then dropped it whenever the item already carried a
 * youtube.com link. Found 2026-09-04; resolution is longest-suffix now.
 */
it('answers the most specific platform for a shadowed host', function () {
    expect(ItemLinkRules::platformForUrl('https://music.youtube.com/watch?v=dQw4w9WgXcQ'))->toBe('youtube-music')
        ->and(ItemLinkRules::platformForUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ'))->toBe('youtube')
        ->and(ItemLinkRules::platformForUrl('https://youtu.be/dQw4w9WgXcQ'))->toBe('youtube');
});

/**
 * The durable half. A non-empty shadowing list is not a defect — a brand on a
 * subdomain of another brand's domain is a fact about the world. What must hold
 * is that the shadowed brand still wins its OWN hosts, for whatever pairs exist
 * when this runs. A host added to ItemLinkRules::HOSTS that reintroduces the
 * bug fails here without anyone remembering to write a case for it.
 */
it('never lets a broader brand swallow a brand that lives beneath it', function () {
    $pairs = ItemLinkRules::hostShadowing();

    foreach ($pairs as $p) {
        expect(ItemLinkRules::platformForUrl('https://'.$p['host'].'/anything'))
            ->toBe($p['shadowed'], "https://{$p['host']}/ resolved to the wrong platform — {$p['shadowing']} (on {$p['by']}) swallowed {$p['shadowed']}");
    }

    // Not an assertion about the count: it only says the loop above had
    // something to check, so a refactor that empties hostShadowing() by
    // accident cannot turn this test into a silent no-op.
    expect($pairs)->not->toBeEmpty();
});

/**
 * Every platform named in a pool roster must be reachable from a URL, or the
 * roster entry is decoration: the hand-add control offers it and nothing can
 * ever resolve to it. Brands with no HOSTS entry are skipped — hostsFor()
 * answers [] for them and they are matched by other means.
 */
it('can resolve a url to every roster platform that declares hosts', function () {
    $reachable = [];
    foreach (ItemLinkRules::allPlatforms() as $p) {
        $reachable[$p] = true;
    }

    $unreachable = [];
    foreach (ItemLinkRules::ROSTER as $pool => $platforms) {
        foreach ($platforms as $platform) {
            if (! isset($reachable[$platform])) {
                $unreachable[] = "{$pool}/{$platform}";
            }
        }
    }

    expect($unreachable)->toBe([], 'roster platforms with no entry in platformForUrl()\'s search: '.implode(', ', $unreachable));
});
