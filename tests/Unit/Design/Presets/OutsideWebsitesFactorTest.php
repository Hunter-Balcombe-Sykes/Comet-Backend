<?php

/**
 * Unit-level proof of CACHE-3: OutsideWebsitesFactor::detect() must read the
 * ALREADY-LOADED connections collection the resolver hands it, not issue its
 * own IntegrationConnection query. These are unsaved, in-memory models built
 * directly — never persisted — so a passing result can only come from reading
 * the collection argument, not the DB. Mirrors the outside-website mode-vote
 * fixture in WebsiteBrandSignalsTest (~lines 380-399), but drives detect()
 * directly instead of through the full resolver + DB round-trip.
 */

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\ShopBrand;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Design\Presets\Factors\OutsideWebsitesFactor;
use App\Services\Design\WebsiteStyleAnalyzer;
use Illuminate\Support\Collection;
use Tests\TestCase;

// Boots the app container (not the DB): OutsideWebsitesFactor::confidentTiers()
// reads EvidenceConclusions::minConfidence(), which calls config(). No table
// setup here — the point of this test is that detect() reads the PASSED-IN
// collection and never touches the DB at all.
uses(TestCase::class)->in(__FILE__);

/** v2 styleAnalysis document; mirrors WebsiteBrandSignalsTest's wbsAnalysis(). */
function outsideWbsAnalysis(string $url, array $tiers, bool $ok = true): array
{
    $signals = [];
    foreach ($tiers as $signal => $tier) {
        $signals[$signal] = ['tier' => $tier, 'confidence' => 0.8, 'evidence' => ['seeded']];
    }

    return [
        'v' => WebsiteStyleAnalyzer::VERSION, 'url' => $url, 'finalUrl' => $url, 'ok' => $ok,
        'mode' => 'rendered', 'failure' => null, 'accent' => null, 'signals' => $signals,
        'logo' => ['candidates' => []], 'notes' => [],
    ];
}

/** Unsaved IntegrationConnection — never persisted, no DB access needed. */
function outsideWbsConnection(string $userId, string $platform, array $payload): IntegrationConnection
{
    return new IntegrationConnection([
        'user_id' => $userId,
        'platform' => $platform,
        'payload' => $payload,
    ]);
}

/**
 * Unsaved shop connection with its brand rows pre-set via setRelation() (FOUND-25:
 * shop brands are relational site.shop_brands rows now, not a payload map) — the
 * relation is already "loaded" in memory, so OutsideWebsitesFactor reading
 * $connection->shopBrands never issues a query, preserving this test's whole
 * point (no DB access).
 *
 * @param  list<array{id:string, url:string, styleAnalysis:array}>  $brands
 */
function outsideWbsShopConnection(string $userId, array $brands): IntegrationConnection
{
    $connection = outsideWbsConnection($userId, 'shop', ['storage' => 'relational']);

    $connection->setRelation('shopBrands', new Collection(array_map(
        fn (array $b) => (new ShopBrand)->forceFill([
            'brand_id' => $b['id'],
            'url' => $b['url'],
            'style_analysis' => $b['styleAnalysis'],
        ]),
        $brands,
    )));

    return $connection;
}

it('reads outside-website analyses from the passed-in collection, not a DB query', function () {
    $user = (new User)->forceFill(['id' => 'outside-unit-user']);
    $site = (new Site)->forceFill(['id' => 'outside-unit-site']);

    $connections = new Collection([
        outsideWbsConnection($user->id, 'custom', [
            'kind' => 'link', 'url' => 'https://l1.test',
            'styleAnalysis' => outsideWbsAnalysis('https://l1.test', ['bg' => 'dark']),
        ]),
        outsideWbsConnection($user->id, 'custom', [
            'kind' => 'link', 'url' => 'https://l2.test',
            'styleAnalysis' => outsideWbsAnalysis('https://l2.test', ['bg' => 'dark']),
        ]),
        outsideWbsConnection($user->id, 'custom', [
            'kind' => 'link', 'url' => 'https://l3.test',
            'styleAnalysis' => outsideWbsAnalysis('https://l3.test', ['bg' => 'dark']),
        ]),
        outsideWbsShopConnection($user->id, [
            ['id' => 'b1', 'url' => 'https://s1.test', 'styleAnalysis' => outsideWbsAnalysis('https://s1.test', ['bg' => 'dark', 'font' => 'geist'])],
            ['id' => 'b2', 'url' => 'https://s2.test', 'styleAnalysis' => outsideWbsAnalysis('https://s2.test', ['bg' => 'warm_light', 'font' => 'geist'])],
        ]),
        // Non-source platform: must be excluded by the in-memory
        // whereIn(SOURCE_PLATFORMS) filter, same as the dropped query's clause.
        outsideWbsConnection($user->id, 'instagram', [
            'styleAnalysis' => outsideWbsAnalysis('https://ig.test', ['bg' => 'light']),
        ]),
    ]);

    $result = (new OutsideWebsitesFactor)->detect($user, $site, $connections);

    expect($result)->toBe([
        'color_bg' => '#151515',                       // 4 dark vs 1 warm_light -> dark
        'typography_font_family' => 'geist',  // 2 votes, unopposed
    ]);
});
