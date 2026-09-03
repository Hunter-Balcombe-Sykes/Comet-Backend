<?php

use App\Services\Platforms\MediaPageReader;
use App\Site\Pools\ItemLinkRules;
use App\Site\Pools\PoolRegistry;

// The real-URL gate for the ITEM grammar, the counterpart to
// tests/Unit/Routing/RoutingCorpusTest's real-URL half for the PLATFORM one.
//
// Those two corpora answer the two halves of the same question about a pasted
// link — "whose is it?" and "is it one thing, or the whole account?" — and both
// need real URLs to be worth anything. The grammar is pure, so nothing here
// touches the network.

function itemCorpus(): array
{
    static $cases = null;

    return $cases ??= require base_path('tests/fixtures/Content/item-url-corpus.php');
}

function itemReader(): MediaPageReader
{
    return app(MediaPageReader::class);
}

it('recognises every real item URL, with its kind, pool and canonical form', function () {
    $cases = itemCorpus()['items'];
    expect($cases)->not->toBeEmpty();

    $failures = [];
    foreach ($cases as $case) {
        $item = itemReader()->classifyItem($case['url']);

        if ($item === null) {
            $failures[] = "{$case['shape']}: {$case['url']} → not recognised as an item at all";

            continue;
        }
        if ($item['platform'] !== $case['platform'] || $item['kind'] !== $case['kind']) {
            $failures[] = sprintf('%s: %s → %s/%s, expected %s/%s',
                $case['shape'], $case['url'], $item['platform'], $item['kind'], $case['platform'], $case['kind']);

            continue;
        }
        // The canonical is what the identity spine folds a pasted item onto its
        // synced twin with, so a drift here silently duplicates content rather
        // than failing loudly.
        if ($item['canonical'] !== $case['canonical']) {
            $failures[] = "{$case['shape']}: {$case['url']} → canonical '{$item['canonical']}', expected '{$case['canonical']}'";

            continue;
        }
        if (PoolRegistry::poolForKind($item['kind']) !== $case['pool']) {
            $failures[] = "{$case['shape']}: kind '{$item['kind']}' no longer lands in the {$case['pool']} pool";
        }
    }

    expect($failures)->toBe([], count($failures).' real item URL(s) regressed:'.PHP_EOL.implode(PHP_EOL, $failures));
});

it('never mistakes a real profile for a single item, and still names it', function () {
    $cases = itemCorpus()['profiles'];
    expect($cases)->not->toBeEmpty();

    $failures = [];
    foreach ($cases as $case) {
        $item = itemReader()->classifyItem($case['url']);

        // The dangerous direction: a channel saved as though it were one video.
        if ($item !== null) {
            $failures[] = sprintf('%s: %s → claimed as a %s/%s — this is an ACCOUNT',
                $case['shape'], $case['url'], $item['platform'], $item['kind']);

            continue;
        }

        // The merely-unhelpful direction, which is still a defect: without a
        // name the pool answers a generic "that isn't a video", instead of
        // telling the person to connect the platform they just pasted.
        $account = itemReader()->accountPlatformLabel($case['url']);
        if ($account !== $case['account']) {
            $failures[] = sprintf("%s: %s → account label '%s', expected '%s'",
                $case['shape'], $case['url'], $account ?? 'null', $case['account']);
        }
    }

    expect($failures)->toBe([], count($failures).' real profile URL(s) regressed:'.PHP_EOL.implode(PHP_EOL, $failures));
});

it('keeps the item grammar and the pool rosters in step', function () {
    // A platform the grammar can mint an item for, but whose pool roster omits
    // it, is a live inconsistency: the paste lane creates the item and the
    // per-item "add a link" control then refuses that same platform. TikTok was
    // exactly this shape until 2026-09-03 — in neither.
    $roster = ItemLinkRules::ROSTER;

    $missing = [];
    foreach (itemCorpus()['items'] as $case) {
        $pool = $case['pool'];
        if (! isset($roster[$pool])) {
            continue; // pool carries no per-item link roster at all
        }
        if (! in_array($case['platform'], $roster[$pool], true)) {
            $missing[] = "{$case['platform']} mints a '{$case['kind']}' for the {$pool} pool but is not in ItemLinkRules::ROSTER['{$pool}']";
        }
    }

    expect(array_values(array_unique($missing)))->toBe([]);
});
