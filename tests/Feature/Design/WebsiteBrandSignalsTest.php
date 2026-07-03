<?php

use App\Jobs\Design\AnalyzeConnectionWebsitesJob;
use App\Jobs\Design\AnalyzePreviousWebsiteJob;
use App\Jobs\Design\ResolveDesignPresetsJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\User;
use App\Services\Design\LogoAutoGrabber;
use App\Services\Design\Presets\DesignFactorRegistry;
use App\Services\Design\Presets\DesignPresetResolver;
use App\Services\Design\Presets\Factors\GoogleBusinessTypeFactor;
use App\Services\Design\Presets\Factors\InstagramCategoryFactor;
use App\Services\Design\Presets\Factors\OutsideWebsitesFactor;
use App\Services\Design\Presets\Factors\PreviousWebsiteFactor;
use App\Services\Design\Presets\StyleTiers;
use App\Services\Design\Scan\BrandScanClient;
use App\Services\Design\Scan\EvidenceConclusions;
use App\Services\Design\Scan\ScreenshotSampler;
use App\Services\Design\WebsiteStyleAnalyzer;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Media\MediaUploadService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();              // also creates site.platform_connections
    setupDesignKitsTable();
    setupDesignKitContributionsTable();
    setupWorkplacesTable();

    config()->set('partna.brand_scan', [
        'enabled' => true, 'url' => 'https://scan.test', 'token' => 'test-secret', 'timeout' => 5,
    ]);
});

// ── Harness ────────────────────────────────────────────────────────────────

/** Fetcher stub: URL → canned response array; SSRF check no-ops (no DNS in tests). */
function wbsFetcher(array $map = []): SafeUrlFetcher
{
    return new class($map) extends SafeUrlFetcher
    {
        public function __construct(private readonly array $map) {}

        public function assertPublicUrl(string $url): void {}

        public function fetch(string $url, array $headers = []): array
        {
            return $this->map[$url] ?? throw new RuntimeException("unfixtured {$url}");
        }

        public function tryFetch(string $url, array $headers = []): ?array
        {
            return $this->map[$url] ?? null;
        }

        public function fetchMany(array $urls, array $headers = []): array
        {
            $out = [];
            foreach ($urls as $url) {
                $out[$url] = $this->map[$url] ?? null;
            }

            return $out;
        }
    };
}

function wbsResponse(string $body, string $url, string $type = 'text/html'): array
{
    return ['status' => 200, 'body' => $body, 'finalUrl' => $url, 'contentType' => $type];
}

/** Production-wired analyzer with a stubbed (network-free) SSRF fetcher. */
function wbsAnalyzer(): WebsiteStyleAnalyzer
{
    return new WebsiteStyleAnalyzer(new BrandScanClient, new ScreenshotSampler, new EvidenceConclusions, wbsFetcher());
}

/** Collector evidence with sane light-page defaults; top-level keys REPLACE. */
function wbsEvidence(array $overrides = []): array
{
    return array_merge([
        'v' => 1,
        'ok' => true,
        'url' => 'https://example.test/',
        'page' => [
            'bodyBg' => 'rgb(255, 255, 255)', 'bodyBgImage' => false, 'deepBg' => null, 'mainBg' => null,
            'bodyColor' => 'rgb(20, 20, 20)', 'bodyFont' => 'Poppins, sans-serif',
            'bodyFontSize' => 17, 'bodyFontWeight' => '300',
        ],
        'header' => null,
        'copy' => array_fill(0, 3, ['size' => 17, 'weight' => '300', 'family' => 'Poppins, sans-serif', 'color' => 'rgb(20,20,20)']),
        'headings' => [],
        'buttons' => array_fill(0, 3, ['bg' => 'rgba(0, 0, 0, 0)', 'color' => 'rgb(20,20,20)', 'radius' => 14, 'height' => 44, 'transition' => 150, 'cls' => 'btn']),
        'links' => [],
        'sections' => [],
        'rootVars' => [],
        'meta' => ['themeColor' => null, 'manifest' => null, 'icons' => [], 'ogImage' => null, 'twitterImage' => null],
        'logos' => [],
        'errors' => [],
    ], $overrides);
}

/** Fake the brand-scan Worker: stash the evidence into snapshot content. */
function wbsFakeWorker(?array $evidence, ?string $png = null): void
{
    $content = $evidence === null
        ? '<html><body>no collector</body></html>'
        : '<html data-partna-scan="'.htmlspecialchars(json_encode($evidence), ENT_QUOTES).'"><body></body></html>';

    Http::fake([
        'scan.test/*' => Http::response([
            'success' => true,
            'result' => ['content' => $content, 'screenshot' => $png === null ? '' : base64_encode($png)],
        ]),
    ]);
}

/** Resolver wired exactly like production (both factor lists). */
function wbsResolver(): DesignPresetResolver
{
    return new DesignPresetResolver(new DesignFactorRegistry(
        [new GoogleBusinessTypeFactor, new InstagramCategoryFactor],
        [new PreviousWebsiteFactor, new OutsideWebsitesFactor],
    ));
}

function wbsSeedConnection(User $user, array $payload, string $platform): string
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => $id, 'user_id' => $user->id, 'platform' => $platform,
        'resource_id' => 'res-'.Str::random(6), 'payload' => json_encode($payload),
        'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);

    return $id;
}

function wbsSeedWorkplace(string $siteId, ?string $url, ?array $analysis): void
{
    DB::connection('pgsql')->table('site.workplaces')->insert([
        'site_id' => $siteId,
        'previous_website' => $url,
        'previous_website_analysis' => $analysis === null ? null : json_encode($analysis),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

/** v2 analysis document; $tiers become confident signals. */
function wbsAnalysis(string $url, ?string $accent, array $tiers, bool $ok = true, int $v = WebsiteStyleAnalyzer::VERSION): array
{
    $signals = [];
    foreach ($tiers as $signal => $tier) {
        $signals[$signal] = ['tier' => $tier, 'confidence' => 0.8, 'evidence' => ['seeded']];
    }

    return [
        'v' => $v, 'url' => $url, 'finalUrl' => $url, 'ok' => $ok, 'mode' => 'rendered', 'failure' => null,
        'analyzedAt' => now()->toIso8601String(),
        'accent' => $accent === null ? null : ['hex' => $accent, 'confidence' => 0.9, 'evidence' => ['seeded']],
        'signals' => $signals,
        'logo' => ['candidates' => []],
        'notes' => [],
    ];
}

/** Minimal in-memory solid PNG (GD). */
function wbsPngBytes(int $w, int $h, int $r = 200, int $g = 60, int $b = 30): string
{
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, $r, $g, $b));
    ob_start();
    imagepng($img);

    return (string) ob_get_clean();
}

// ── WebsiteStyleAnalyzer v2 (rendered evidence) ────────────────────────────

it('concludes signals from rendered evidence with pixel corroboration', function () {
    wbsFakeWorker(wbsEvidence(), wbsPngBytes(400, 400, 255, 255, 255));

    $a = wbsAnalyzer()->analyze('https://example.test/');

    expect($a['ok'])->toBeTrue()
        ->and($a['v'])->toBe(2)
        ->and($a['mode'])->toBe('rendered')
        ->and($a['signals']['bg']['tier'])->toBe('light')
        ->and($a['signals']['bg']['confidence'])->toBe(0.95)   // computed + pixels agree
        ->and($a['signals']['font']['tier'])->toBe('forma-djr') // Poppins → warm sans
        ->and($a['signals']['weight']['tier'])->toBe('light')
        ->and($a['signals']['text']['tier'])->toBe('regular')
        ->and($a['signals']['radius']['tier'])->toBe('rounded')
        ->and($a['signals']['motion']['tier'])->toBe('fast')
        ->and($a['accent'])->toBeNull(); // transparent buttons, no vars → monochrome
});

it('brotherwolf regression: light bg, no accent, sharp radius, fast motion', function () {
    // Real rendered evidence captured from brotherwolf.com.au — the site v1
    // read as dark #151515 with a Shopify app widget's #4caf50 as "brand".
    $evidence = json_decode((string) file_get_contents(base_path('tests/fixtures/design-scan/brotherwolf-evidence.json')), true);
    wbsFakeWorker($evidence);

    $a = wbsAnalyzer()->analyze('https://brotherwolf.com.au/');

    expect($a['ok'])->toBeTrue()
        ->and($a['signals']['bg']['tier'])->toBe('light')
        ->and($a['signals']['bg']['tier'])->not->toBe('dark')
        ->and($a['accent'])->toBeNull()
        ->and($a['signals']['radius']['tier'])->toBe('sharp')
        ->and($a['signals']['motion']['tier'])->toBe('fast')
        ->and($a['signals']['font']['tier'])->toBe('helvetica-neue')
        ->and(collect($a['logo']['candidates'])->pluck('kind'))->toContain('header-img');
});

it('finds an accent via CTA quorum and rejects one indistinguishable from the background', function () {
    // Http::fake stubs are first-match-wins, so one closure serves all three
    // scenarios keyed by the scanned target URL.
    $red = ['bg' => 'rgb(188, 23, 28)', 'color' => '#fff', 'radius' => 8, 'height' => 44, 'transition' => 150, 'cls' => 'cta'];
    $byTarget = [
        'https://quorum.test/' => wbsEvidence(['buttons' => array_fill(0, 4, $red)]),
        'https://twosource.test/' => wbsEvidence([
            'buttons' => array_fill(0, 2, $red),
            'rootVars' => ['--color-accent' => 'rgb(188 23 28 / 1.0)'],
        ]),
        'https://bgmatch.test/' => wbsEvidence([
            'page' => ['bodyBg' => 'rgb(224, 73, 31)', 'bodyBgImage' => false, 'bodyColor' => '#111', 'bodyFont' => 'Arial', 'bodyFontSize' => 16, 'bodyFontWeight' => '400'],
            'buttons' => array_fill(0, 3, ['bg' => 'rgb(224, 73, 31)', 'color' => '#fff', 'radius' => 8, 'height' => 44, 'transition' => 150, 'cls' => 'cta']),
        ]),
    ];
    Http::fake(function ($request) use ($byTarget) {
        $evidence = $byTarget[(string) ($request->data()['url'] ?? '')] ?? wbsEvidence();
        $content = '<html data-partna-scan="'.htmlspecialchars(json_encode($evidence), ENT_QUOTES).'"></html>';

        return Http::response(['success' => true, 'result' => ['content' => $content, 'screenshot' => '']]);
    });

    // Four agreeing saturated CTAs → single-source multi-vote confidence.
    $a = wbsAnalyzer()->analyze('https://quorum.test/');
    expect($a['accent']['hex'])->toBe('#bc171c')
        ->and($a['accent']['confidence'])->toBe(0.7);

    // Same color also declared as a brand var → two distinct sources → 0.9.
    $a = wbsAnalyzer()->analyze('https://twosource.test/');
    expect($a['accent']['confidence'])->toBe(0.9);

    // A saturated "accent" equal to the page bg is a mis-read → null.
    expect(wbsAnalyzer()->analyze('https://bgmatch.test/')['accent'])->toBeNull();
});

it('abstains on computed-vs-pixel background disagreement', function () {
    // Evidence says white, screenshot is solid near-black → conf 0.25 → omitted.
    wbsFakeWorker(wbsEvidence(), wbsPngBytes(400, 400, 12, 12, 12));

    $a = wbsAnalyzer()->analyze('https://example.test/');

    expect($a['signals'])->not->toHaveKey('bg')
        ->and($a['signals']['font']['tier'])->toBe('forma-djr'); // other signals unaffected
});

it('degrades to pixels-only mode when the collector stash is missing (CSP)', function () {
    wbsFakeWorker(null, wbsPngBytes(400, 400, 21, 21, 21));

    $a = wbsAnalyzer()->analyze('https://csp.test/');

    expect($a['ok'])->toBeTrue()
        ->and($a['mode'])->toBe('pixels-only')
        ->and($a['failure'])->toBe('csp')
        ->and($a['signals']['bg']['tier'])->toBe('dark')
        ->and($a['signals']['bg']['confidence'])->toBe(0.55)
        ->and($a['signals'])->not->toHaveKey('font')
        ->and($a['accent'])->toBeNull();
});

it('stores the failure kind when the worker errors, and when unconfigured', function () {
    Http::fake(['scan.test/*' => Http::response('boom', 500)]);
    $a = wbsAnalyzer()->analyze('https://down.test/');
    expect($a['ok'])->toBeFalse()->and($a['failure'])->toBe('fetch')->and($a['signals'])->toBe([]);

    config()->set('partna.brand_scan.url', '');
    $a = wbsAnalyzer()->analyze('https://any.test/');
    expect($a['ok'])->toBeFalse()->and($a['failure'])->toBe('disabled');
});

it('maps tiers to columns, drops unknown tiers, and knows the new light tier', function () {
    expect(StyleTiers::columnsFromTiers(['bg' => 'made-up', 'radius' => 'sharp', 'nonsense' => 'x']))
        ->toBe(['border_radius' => '0.25rem'])
        ->and(StyleTiers::columnsFromTiers(['bg' => 'light']))
        ->toBe(['color_bg' => '#fafafa']);
});

// ── PreviousWebsiteFactor (priority 100) ───────────────────────────────────

it('contributes raw accent + snapped tiers from a stored analysis, beating Google', function () {
    $user = createTenant('pw-wins');
    // Google restaurant would set color_bg #f7f4ee at priority 50…
    wbsSeedConnection($user, ['category' => 'Restaurant'], 'google-business');
    // …but the previous website concluded dark at priority 100.
    wbsSeedWorkplace($user->site->id, 'https://old.example', wbsAnalysis(
        'https://old.example', '#123abc', ['bg' => 'dark', 'radius' => 'sharp'],
    ));

    wbsResolver()->resolveForUser($user);
    $layer = wbsResolver()->presetLayer($user->site->id);

    expect($layer['color_bg'])->toBe('#151515')       // previous-website (100) beats Google (50)
        ->and($layer['color_accent'])->toBe('#123abc') // raw accent passes through
        ->and($layer['border_radius'])->toBe('0.25rem')
        ->and($layer['typography_font_family'])->toBe('forma-djr'); // Google still wins uncontested columns
});

it('ignores below-threshold signals and low-confidence accents', function () {
    $user = createTenant('pw-lowconf');
    $analysis = wbsAnalysis('https://old.example', null, ['bg' => 'dark', 'radius' => 'sharp']);
    $analysis['signals']['bg']['confidence'] = 0.25;                       // disagreement-grade
    $analysis['accent'] = ['hex' => '#4caf50', 'confidence' => 0.35, 'evidence' => ['theme-color']];
    wbsSeedWorkplace($user->site->id, 'https://old.example', $analysis);

    wbsResolver()->resolveForUser($user);
    $layer = wbsResolver()->presetLayer($user->site->id);

    expect($layer)->not->toHaveKey('color_bg')
        ->and($layer)->not->toHaveKey('color_accent')
        ->and($layer['border_radius'])->toBe('0.25rem');
});

it('contributes nothing from a v1 (pre-rebuild) analysis document', function () {
    $user = createTenant('pw-v1doc');
    wbsSeedWorkplace($user->site->id, 'https://old.example', [
        'v' => 1, 'url' => 'https://old.example', 'ok' => true,
        'accent' => '#4caf50', 'tiers' => ['bg' => 'dark'], 'logoCandidates' => [],
    ]);

    wbsResolver()->resolveForUser($user);

    expect(wbsResolver()->presetLayer($user->site->id))->toBe([]);
});

it('contributes nothing when the stored analysis is stale (URL changed since)', function () {
    $user = createTenant('pw-stale');
    wbsSeedWorkplace($user->site->id, 'https://new.example', wbsAnalysis(
        'https://old.example', '#123abc', ['bg' => 'dark'],
    ));

    wbsResolver()->resolveForUser($user);

    expect(wbsResolver()->presetLayer($user->site->id))->toBe([]);
});

it('sweeps previous-website contributions when the URL is cleared', function () {
    $user = createTenant('pw-cleared');
    wbsSeedWorkplace($user->site->id, 'https://old.example', wbsAnalysis(
        'https://old.example', null, ['bg' => 'dark'],
    ));
    wbsResolver()->resolveForUser($user);
    expect(wbsResolver()->presetLayer($user->site->id))->not->toBe([]);

    DB::connection('pgsql')->table('site.workplaces')
        ->where('site_id', $user->site->id)
        ->update(['previous_website' => null, 'previous_website_analysis' => null]);

    wbsResolver()->resolveForUser($user);

    expect(wbsResolver()->presetLayer($user->site->id))->toBe([]);
});

// ── OutsideWebsitesFactor (priority 10, mode aggregation) ──────────────────

it('applies the mode across outside websites: 4 dark + 1 light -> dark', function () {
    $user = createTenant('outside-mode');
    foreach (range(1, 3) as $i) {
        wbsSeedConnection($user, [
            'kind' => 'link', 'url' => "https://l{$i}.test",
            'styleAnalysis' => wbsAnalysis("https://l{$i}.test", null, ['bg' => 'dark']),
        ], 'custom');
    }
    // Shop connection: brand-keyed map, one dark + one warm brand.
    wbsSeedConnection($user, [
        'b1' => ['id' => 'b1', 'url' => 'https://s1.test', 'styleAnalysis' => wbsAnalysis('https://s1.test', null, ['bg' => 'dark', 'font' => 'nb-architekt'])],
        'b2' => ['id' => 'b2', 'url' => 'https://s2.test', 'styleAnalysis' => wbsAnalysis('https://s2.test', null, ['bg' => 'warm_light', 'font' => 'nb-architekt'])],
    ], 'shop');

    wbsResolver()->resolveForUser($user);
    $layer = wbsResolver()->presetLayer($user->site->id);

    expect($layer['color_bg'])->toBe('#151515')                       // 4 dark vs 1 warm → dark
        ->and($layer['typography_font_family'])->toBe('nb-architekt'); // 2 votes, unopposed
});

it('skips a column when the outside-website vote is tied', function () {
    $user = createTenant('outside-tie');
    wbsSeedConnection($user, [
        'kind' => 'link', 'url' => 'https://a.test',
        'styleAnalysis' => wbsAnalysis('https://a.test', null, ['bg' => 'dark', 'radius' => 'sharp']),
    ], 'custom');
    wbsSeedConnection($user, [
        'kind' => 'link', 'url' => 'https://b.test',
        'styleAnalysis' => wbsAnalysis('https://b.test', null, ['bg' => 'warm_light', 'radius' => 'sharp']),
    ], 'custom');

    wbsResolver()->resolveForUser($user);
    $layer = wbsResolver()->presetLayer($user->site->id);

    expect($layer)->not->toHaveKey('color_bg')                 // 1–1 tie → no conclusion
        ->and($layer['border_radius'])->toBe('0.25rem');        // unanimous column still lands
});

it('loses every contested column to Instagram (30 beats 10)', function () {
    $user = createTenant('outside-loses');
    wbsSeedConnection($user, ['businessCategory' => 'Restaurant'], 'instagram'); // food bucket @30
    wbsSeedConnection($user, [
        'kind' => 'link', 'url' => 'https://c.test',
        'styleAnalysis' => wbsAnalysis('https://c.test', null, ['bg' => 'dark']),
    ], 'custom');

    wbsResolver()->resolveForUser($user);

    expect(wbsResolver()->presetLayer($user->site->id)['color_bg'])->toBe('#f7f4ee');
});

it('ignores failed, missing, and v1 analyses in the vote', function () {
    $user = createTenant('outside-failed');
    wbsSeedConnection($user, [
        'kind' => 'link', 'url' => 'https://ok.test',
        'styleAnalysis' => wbsAnalysis('https://ok.test', null, ['bg' => 'cool_light']),
    ], 'custom');
    wbsSeedConnection($user, [
        'kind' => 'link', 'url' => 'https://broken.test',
        'styleAnalysis' => wbsAnalysis('https://broken.test', null, [], ok: false),
    ], 'custom');
    wbsSeedConnection($user, [
        'kind' => 'link', 'url' => 'https://legacy.test',
        'styleAnalysis' => wbsAnalysis('https://legacy.test', null, ['bg' => 'dark'], v: 1),
    ], 'custom');
    wbsSeedConnection($user, ['kind' => 'link', 'url' => 'https://new.test'], 'custom');

    wbsResolver()->resolveForUser($user);

    expect(wbsResolver()->presetLayer($user->site->id)['color_bg'])->toBe('#f7f8fa');
});

// ── Observer + reconciliation wiring ───────────────────────────────────────

it('dispatches the analyze job when a workplace URL and its analysis disagree', function () {
    Queue::fake();
    $user = createTenant('observer-dispatch');

    Workplace::query()->create(['site_id' => $user->site->id, 'previous_website' => 'https://old.example']);

    Queue::assertPushed(AnalyzePreviousWebsiteJob::class, 1);
});

it('does not dispatch when a current-version analysis matches the URL', function () {
    $user = createTenant('observer-quiet');
    Queue::fake();

    Workplace::query()->create([
        'site_id' => $user->site->id,
        'previous_website' => 'https://old.example',
        'previous_website_analysis' => wbsAnalysis('https://old.example', null, []),
    ]);

    Queue::assertNotPushed(AnalyzePreviousWebsiteJob::class);
});

it('re-dispatches when the stored analysis is from an older analyzer version', function () {
    $user = createTenant('observer-version');
    Queue::fake();

    Workplace::query()->create([
        'site_id' => $user->site->id,
        'previous_website' => 'https://old.example',
        'previous_website_analysis' => wbsAnalysis('https://old.example', null, [], v: 1),
    ]);

    Queue::assertPushed(AnalyzePreviousWebsiteJob::class, 1);
});

it('queues analyses + a resolve when an outside-website connection is created', function () {
    Queue::fake();
    $user = createTenant('conn-dispatch');

    IntegrationConnection::query()->create([
        'user_id' => $user->id, 'platform' => 'custom', 'resource_id' => 'link-abc',
        'payload' => ['kind' => 'link', 'url' => 'https://fresh.test'], 'is_active' => true,
    ]);

    Queue::assertPushed(AnalyzeConnectionWebsitesJob::class, 1);
    Queue::assertPushed(ResolveDesignPresetsJob::class);
});

it('fills missing styleAnalysis for custom links and shop brands, then converges', function () {
    Queue::fake();
    $user = createTenant('analyses-fill');
    $linkId = wbsSeedConnection($user, ['kind' => 'link', 'url' => 'https://site1.test'], 'custom');
    $shopId = wbsSeedConnection($user, [
        'b1' => ['id' => 'b1', 'url' => 'https://store.test'],
    ], 'shop');

    // Worker fake keyed on the scanned target inside the request body.
    Http::fake(function ($request) {
        $target = (string) ($request->data()['url'] ?? '');
        $evidence = str_contains($target, 'site1')
            ? wbsEvidence(['url' => $target, 'page' => ['bodyBg' => 'rgb(17,17,17)', 'bodyBgImage' => false, 'bodyColor' => '#eee', 'bodyFont' => 'Arial', 'bodyFontSize' => 16, 'bodyFontWeight' => '400']])
            : wbsEvidence(['url' => $target, 'page' => ['bodyBg' => 'rgb(248, 244, 236)', 'bodyBgImage' => false, 'bodyColor' => '#111', 'bodyFont' => 'Arial', 'bodyFontSize' => 16, 'bodyFontWeight' => '400']]);
        $content = '<html data-partna-scan="'.htmlspecialchars(json_encode($evidence), ENT_QUOTES).'"></html>';

        return Http::response(['success' => true, 'result' => ['content' => $content, 'screenshot' => '']]);
    });

    (new AnalyzeConnectionWebsitesJob((string) $user->id))->handle(wbsAnalyzer());

    $link = IntegrationConnection::query()->find($linkId);
    $shop = IntegrationConnection::query()->find($shopId);
    expect($link->payload['styleAnalysis']['signals']['bg']['tier'])->toBe('dark')
        ->and($shop->payload['b1']['styleAnalysis']['signals']['bg']['tier'])->toBe('warm_light')
        ->and(AnalyzeConnectionWebsitesJob::connectionNeedsAnalyses($link->fresh()))->toBeFalse()
        ->and(AnalyzeConnectionWebsitesJob::connectionNeedsAnalyses($shop->fresh()))->toBeFalse();
});

it('treats a v1 styleAnalysis as needing re-analysis', function () {
    $user = createTenant('needs-v1');
    $id = wbsSeedConnection($user, [
        'kind' => 'link', 'url' => 'https://old.test',
        'styleAnalysis' => wbsAnalysis('https://old.test', null, ['bg' => 'dark'], v: 1),
    ], 'custom');

    expect(AnalyzeConnectionWebsitesJob::connectionNeedsAnalyses(IntegrationConnection::query()->find($id)))->toBeTrue();
});

// ── AnalyzeConnectionWebsitesJob per-brand checkpoint + self-continue (LIFE-1) ──

it('checkpoints each shop brand immediately so a mid-run failure keeps prior progress', function () {
    // Fake the queue so the b1 checkpoint's own IntegrationConnectionObserver
    // dispatch (payload changed → AnalyzeConnectionWebsitesJob re-fires) doesn't
    // run for real mid-test and clobber b2 with a genuine (SSRF-rejected)
    // analysis before our own loop gets to it.
    Queue::fake();
    $user = createTenant('life1-checkpoint');
    $shopId = wbsSeedConnection($user, [
        'b1' => ['id' => 'b1', 'url' => 'https://b1.test'],
        'b2' => ['id' => 'b2', 'url' => 'https://b2.test'],
    ], 'shop');

    $analyzer = Mockery::mock(WebsiteStyleAnalyzer::class);
    $analyzer->shouldReceive('analyze')->once()->with('https://b1.test')
        ->andReturn(wbsAnalysis('https://b1.test', null, ['bg' => 'dark']));
    $analyzer->shouldReceive('analyze')->once()->with('https://b2.test')
        ->andThrow(new RuntimeException('scan failed'));

    try {
        (new AnalyzeConnectionWebsitesJob((string) $user->id))->handle($analyzer);
    } catch (RuntimeException) {
        // Expected — b2's analyze() call throws mid-run; the checkpoint under
        // test is that b1's write already landed before this happened.
    }

    $shop = IntegrationConnection::query()->find($shopId);
    expect($shop->payload['b1']['styleAnalysis']['ok'] ?? null)->toBeTrue()
        ->and($shop->payload['b2']['styleAnalysis'] ?? null)->toBeNull();
});

it('caps analyses per run at the budget and self-continues with a delayed follow-up', function () {
    Queue::fake();
    $user = createTenant('life1-budget');
    foreach (range(1, 16) as $i) {
        wbsSeedConnection($user, ['kind' => 'link', 'url' => "https://cap{$i}.test"], 'custom');
    }

    $analyzer = Mockery::mock(WebsiteStyleAnalyzer::class);
    $analyzer->shouldReceive('analyze')
        ->times(15)
        ->andReturnUsing(fn (string $url) => wbsAnalysis($url, null, ['bg' => 'dark']));

    (new AnalyzeConnectionWebsitesJob((string) $user->id))->handle($analyzer);

    $analyzedCount = IntegrationConnection::query()
        ->where('user_id', $user->id)
        ->get()
        ->filter(fn ($c) => isset($c->payload['styleAnalysis']))
        ->count();

    expect($analyzedCount)->toBe(15);
    Queue::assertPushed(AnalyzeConnectionWebsitesJob::class, fn ($job) => $job->userId === (string) $user->id);
});

// ── AnalyzePreviousWebsiteJob + logo grab v2 ───────────────────────────────

it('stores the analysis, grabs the best square candidate, and records decisions', function () {
    Queue::fake();
    setupMediaTables();
    $user = createTenant('pw-job');
    wbsSeedWorkplace($user->site->id, 'https://old.example', null);

    wbsFakeWorker(wbsEvidence([
        'url' => 'https://old.example',
        'meta' => ['themeColor' => null, 'manifest' => null, 'ogImage' => null, 'twitterImage' => null, 'icons' => [
            ['rel' => 'apple-touch-icon', 'href' => 'https://old.example/icon.png', 'sizes' => '200x200', 'type' => 'image/png'],
        ]],
    ]));

    $fetcher = wbsFetcher([
        'https://old.example/icon.png' => wbsResponse(wbsPngBytes(200, 200), 'https://old.example/icon.png', 'image/png'),
    ]);

    $uploads = Mockery::mock(MediaUploadService::class);
    $uploads->shouldReceive('uploadSingleton')->once()->withArgs(
        fn ($pro, $site, $file, $purpose) => $purpose === SiteMedia::PURPOSE_LOGO_SQUARE,
    )->andReturn(new SiteMedia);

    (new AnalyzePreviousWebsiteJob($user->site->id))->handle(
        new WebsiteStyleAnalyzer(new BrandScanClient, new ScreenshotSampler, new EvidenceConclusions, $fetcher),
        new LogoAutoGrabber($fetcher, $uploads),
    );

    $analysis = Workplace::query()->find($user->site->id)->previous_website_analysis;
    $uploaded = collect($analysis['logo']['grab'])->first(fn ($d) => str_starts_with($d['outcome'], 'uploaded'));
    expect($analysis['ok'])->toBeTrue()
        ->and($analysis['v'])->toBe(2)
        ->and($uploaded)->not->toBeNull()
        ->and($uploaded['slot'])->toBe(SiteMedia::PURPOSE_LOGO_SQUARE);
    Queue::assertPushed(ResolveDesignPresetsJob::class);
});

it('prefers the rendered header logo over og:image for the full slot', function () {
    setupMediaTables();
    $user = createTenant('logo-rank');

    $fetcher = wbsFetcher([
        'https://rank.test/header-logo.png' => wbsResponse(wbsPngBytes(600, 160), 'https://rank.test/header-logo.png', 'image/png'),
        'https://rank.test/og.png' => wbsResponse(wbsPngBytes(900, 300), 'https://rank.test/og.png', 'image/png'),
    ]);
    $uploads = Mockery::mock(MediaUploadService::class);
    $uploads->shouldReceive('uploadSingleton')->once()->andReturn(new SiteMedia);

    $decisions = (new LogoAutoGrabber($fetcher, $uploads))->grabIfEmpty($user, $user->site, [
        ['kind' => 'og-image', 'url' => 'https://rank.test/og.png'],
        ['kind' => 'header-img', 'url' => 'https://rank.test/header-logo.png', 'natW' => 600, 'natH' => 160, 'hint' => true, 'inHeader' => true],
    ]);

    $uploaded = collect($decisions)->first(fn ($d) => str_starts_with($d['outcome'], 'uploaded'));
    expect($uploaded['kind'])->toBe('header-img')->and($uploaded['slot'])->toBe(SiteMedia::PURPOSE_LOGO_FULL);
});

it('rejects a small favicon, a share-card og:image, and disabled-pipeline svg', function () {
    setupMediaTables();
    config()->set('partna.logo_removal.enabled', false);
    $user = createTenant('logo-gates');

    $fetcher = wbsFetcher([
        'https://gate.test/f.png' => wbsResponse(wbsPngBytes(32, 32), 'https://gate.test/f.png', 'image/png'),
        'https://gate.test/og.png' => wbsResponse(wbsPngBytes(1200, 630), 'https://gate.test/og.png', 'image/png'),
    ]);
    $uploads = Mockery::mock(MediaUploadService::class);
    $uploads->shouldNotReceive('uploadSingleton');

    $decisions = (new LogoAutoGrabber($fetcher, $uploads))->grabIfEmpty($user, $user->site, [
        ['kind' => 'icon', 'url' => 'https://gate.test/f.png', 'sizes' => '32x32'],
        ['kind' => 'og-image', 'url' => 'https://gate.test/og.png'],
        ['kind' => 'inline-svg', 'svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10" fill="#f00"/></svg>', 'w' => 100, 'h' => 100],
    ]);

    $outcomes = collect($decisions)->pluck('outcome')->implode(' | ');
    expect($outcomes)->toContain('too-small')
        ->and($outcomes)->toContain('share-card')
        ->and($outcomes)->toContain('svg-pipeline-disabled');
});

it('upgrades known-CDN icon URLs before download', function () {
    setupMediaTables();
    $user = createTenant('logo-upsize');

    // Only the UPSIZED variant is fixtured big enough — passing proves the rewrite ran.
    $fetcher = wbsFetcher([
        'https://cdn.shopify.com/shop/files/favicon.png?width=1024&v=1' => wbsResponse(wbsPngBytes(512, 512), 'https://cdn.shopify.com/shop/files/favicon.png?width=1024&v=1', 'image/png'),
        'https://cdn.shopify.com/shop/files/favicon.png?width=32&v=1' => wbsResponse(wbsPngBytes(32, 32), 'https://cdn.shopify.com/shop/files/favicon.png?width=32&v=1', 'image/png'),
    ]);
    $uploads = Mockery::mock(MediaUploadService::class);
    $uploads->shouldReceive('uploadSingleton')->once()->withArgs(
        fn ($pro, $site, $file, $purpose) => $purpose === SiteMedia::PURPOSE_LOGO_SQUARE,
    )->andReturn(new SiteMedia);

    $decisions = (new LogoAutoGrabber($fetcher, $uploads))->grabIfEmpty($user, $user->site, [
        ['kind' => 'icon', 'url' => 'https://cdn.shopify.com/shop/files/favicon.png?width=32&v=1', 'sizes' => '32x32'],
    ]);

    // 512x512 only exists at the upsized URL — an upload proves the rewrite ran.
    expect(collect($decisions)->pluck('outcome')->implode(' | '))->toContain('uploaded (512x512)');
});

it('extracts the largest PNG frame from an .ico container', function () {
    $small = wbsPngBytes(16, 16);
    $large = wbsPngBytes(128, 128);
    $header = pack('vvv', 0, 1, 2);
    $entrySize = 16;
    $offsetSmall = 6 + 2 * $entrySize;
    $offsetLarge = $offsetSmall + strlen($small);
    $entry = fn (int $w, int $len, int $off) => chr($w === 256 ? 0 : $w).chr($w === 256 ? 0 : $w)."\x00\x00".pack('vv', 1, 32).pack('VV', $len, $off);
    $ico = $header.$entry(16, strlen($small), $offsetSmall).$entry(128, strlen($large), $offsetLarge).$small.$large;

    expect(LogoAutoGrabber::icoLargestPngFrame($ico))->toBe($large)
        ->and(LogoAutoGrabber::icoLargestPngFrame('garbage'))->toBeNull();
});

// ── AnalyzePreviousWebsiteJob reconciliation guard (TEST-9 / LIFE-3) ───────

it('short-circuits without calling the analyzer when a current, url-matching, ok analysis exists', function () {
    $user = createTenant('pw-guard-hit');
    $url = 'https://old.example';
    $seeded = wbsAnalysis($url, '#123abc', ['bg' => 'dark']);
    wbsSeedWorkplace($user->site->id, $url, $seeded);

    $analyzer = Mockery::mock(WebsiteStyleAnalyzer::class);
    $analyzer->shouldNotReceive('analyze');
    $grabber = Mockery::mock(LogoAutoGrabber::class);
    $grabber->shouldNotReceive('grabIfEmpty');

    (new AnalyzePreviousWebsiteJob($user->site->id))->handle($analyzer, $grabber);

    // Untouched — the guard returned before any write.
    expect(Workplace::query()->find($user->site->id)->previous_website_analysis['accent']['hex'])->toBe($seeded['accent']['hex']);
});

it('re-analyzes when the guard misses: stale version, mismatched url, or a prior failure', function (array $seedAnalysis) {
    $user = createTenant('pw-guard-miss-'.Str::random(6));
    $url = 'https://old.example';
    wbsSeedWorkplace($user->site->id, $url, $seedAnalysis);

    $analyzer = Mockery::mock(WebsiteStyleAnalyzer::class);
    $analyzer->shouldReceive('analyze')->once()->with($url)->andReturn(wbsAnalysis($url, null, [], ok: false));
    $grabber = Mockery::mock(LogoAutoGrabber::class);
    $grabber->shouldNotReceive('grabIfEmpty'); // ok:false result must not enter the logo-grab branch

    (new AnalyzePreviousWebsiteJob($user->site->id))->handle($analyzer, $grabber);

    $stored = Workplace::query()->find($user->site->id)->previous_website_analysis;
    expect($stored['url'])->toBe($url)->and($stored['ok'])->toBeFalse();
})->with([
    'stale analyzer version' => [wbsAnalysis('https://old.example', null, ['bg' => 'dark'], v: 1)],
    'mismatched stored url' => [wbsAnalysis('https://different.example', null, ['bg' => 'dark'])],
    'previously failed (ok:false)' => [wbsAnalysis('https://old.example', null, ['bg' => 'dark'], ok: false)],
]);

it('negatively caches an uncaught analyzer exception instead of leaving a stale doc (LIFE-3)', function () {
    Queue::fake();
    $user = createTenant('pw-analyzer-throws');
    $url = 'https://boom.example';
    wbsSeedWorkplace($user->site->id, $url, null);

    $analyzer = Mockery::mock(WebsiteStyleAnalyzer::class);
    $analyzer->shouldReceive('analyze')->once()->with($url)->andThrow(new RuntimeException('boom'));
    $grabber = Mockery::mock(LogoAutoGrabber::class);
    $grabber->shouldNotReceive('grabIfEmpty');

    // Must not bubble — the whole point is to negatively-cache instead of exhausting retries.
    (new AnalyzePreviousWebsiteJob($user->site->id))->handle($analyzer, $grabber);

    $stored = Workplace::query()->find($user->site->id)->previous_website_analysis;
    expect($stored['ok'])->toBeFalse()
        ->and($stored['url'])->toBe($url)
        ->and($stored['v'])->toBe(WebsiteStyleAnalyzer::VERSION);

    Queue::assertPushed(ResolveDesignPresetsJob::class);
});
