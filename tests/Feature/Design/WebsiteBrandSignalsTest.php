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
use App\Services\Design\WebsiteStyleAnalyzer;
use App\Services\Http\MetadataParser;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Media\MediaUploadService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();              // also creates site.platform_connections
    setupDesignKitsTable();
    setupDesignKitContributionsTable();
    setupWorkplacesTable();
});

/** Fetcher stub: URL → canned response array (no network, no DNS). */
function wbsFetcher(array $map): SafeUrlFetcher
{
    return new class($map) extends SafeUrlFetcher
    {
        public function __construct(private readonly array $map) {}

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

function wbsAnalysis(string $url, ?string $accent, array $tiers, bool $ok = true): array
{
    return [
        'v' => 1, 'url' => $url, 'ok' => $ok,
        'analyzedAt' => now()->toIso8601String(),
        'accent' => $accent, 'tiers' => $tiers, 'logoCandidates' => [],
    ];
}

// ── WebsiteStyleAnalyzer ───────────────────────────────────────────────────

it('analyzes a site: theme-color accent + tiers from inline and linked CSS', function () {
    $html = <<<'HTML'
    <html><head>
      <meta name="theme-color" content="#e0491f">
      <link rel="stylesheet" href="/app.css">
      <style>body { background-color: #faf5ec; font-size: 17px; }</style>
    </head><body></body></html>
    HTML;
    $css = <<<'CSS'
    body { font-family: "Poppins", sans-serif; font-weight: 300; }
    .card { border-radius: 16px; }
    .btn { border-radius: 14px; transition: all 150ms ease; }
    CSS;

    $analyzer = new WebsiteStyleAnalyzer(wbsFetcher([
        'https://example.test' => wbsResponse($html, 'https://example.test'),
        'https://example.test/app.css' => wbsResponse($css, 'https://example.test/app.css', 'text/css'),
    ]), new MetadataParser);

    $a = $analyzer->analyze('https://example.test');

    expect($a['ok'])->toBeTrue()
        ->and($a['accent'])->toBe('#e0491f')
        ->and($a['tiers']['bg'])->toBe('warm_light')
        ->and($a['tiers']['font'])->toBe('forma-djr')
        ->and($a['tiers']['weight'])->toBe('light')
        ->and($a['tiers']['text'])->toBe('regular')
        ->and($a['tiers']['radius'])->toBe('rounded')
        ->and($a['tiers']['motion'])->toBe('fast');
});

it('falls back to brand-named custom properties for accent when theme-color is absent', function () {
    $html = '<html><head><style>:root { --brand-color: rgb(43, 108, 176); } body { background: #10141c; }</style></head><body></body></html>';

    $analyzer = new WebsiteStyleAnalyzer(wbsFetcher([
        'https://dark.test' => wbsResponse($html, 'https://dark.test'),
    ]), new MetadataParser);

    $a = $analyzer->analyze('https://dark.test');

    expect($a['accent'])->toBe('#2b6cb0')
        ->and($a['tiers']['bg'])->toBe('dark');
});

it('returns ok:false when the page cannot be fetched', function () {
    $analyzer = new WebsiteStyleAnalyzer(wbsFetcher([]), new MetadataParser);

    $a = $analyzer->analyze('https://unreachable.test');

    expect($a['ok'])->toBeFalse()->and($a['url'])->toBe('https://unreachable.test');
});

it('draws no bg conclusion from a neutral background and no accent from neutrals', function () {
    $html = '<html><head><style>body { background: #f9f9f9; color: #333; }</style></head><body></body></html>';

    $analyzer = new WebsiteStyleAnalyzer(wbsFetcher([
        'https://plain.test' => wbsResponse($html, 'https://plain.test'),
    ]), new MetadataParser);

    $a = $analyzer->analyze('https://plain.test');

    expect($a['tiers']['bg'])->toBeNull()->and($a['accent'])->toBeNull();
});

it('drops unknown tiers instead of emitting bad values', function () {
    expect(StyleTiers::columnsFromTiers(['bg' => 'made-up', 'radius' => 'sharp', 'nonsense' => 'x']))
        ->toBe(['border_radius' => '0.25rem']);
});

// ── PreviousWebsiteFactor (priority 100) ───────────────────────────────────

it('contributes raw accent + snapped tiers from a stored previous-website analysis, beating Google', function () {
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

it('ignores failed and missing analyses in the vote', function () {
    $user = createTenant('outside-failed');
    wbsSeedConnection($user, [
        'kind' => 'link', 'url' => 'https://ok.test',
        'styleAnalysis' => wbsAnalysis('https://ok.test', null, ['bg' => 'cool_light']),
    ], 'custom');
    wbsSeedConnection($user, [
        'kind' => 'link', 'url' => 'https://broken.test',
        'styleAnalysis' => wbsAnalysis('https://broken.test', null, [], ok: false),
    ], 'custom');
    wbsSeedConnection($user, ['kind' => 'link', 'url' => 'https://new.test'], 'custom');

    wbsResolver()->resolveForUser($user);

    expect(wbsResolver()->presetLayer($user->site->id)['color_bg'])->toBe('#f7f8fa');
});

// ── Observer wiring ────────────────────────────────────────────────────────

it('dispatches the analyze job when a workplace URL and its analysis disagree', function () {
    Queue::fake();
    $user = createTenant('observer-dispatch');

    Workplace::query()->create(['site_id' => $user->site->id, 'previous_website' => 'https://old.example']);

    Queue::assertPushed(AnalyzePreviousWebsiteJob::class, 1);
});

it('does not dispatch when the analysis already matches the URL', function () {
    $user = createTenant('observer-quiet');
    Queue::fake();

    Workplace::query()->create([
        'site_id' => $user->site->id,
        'previous_website' => 'https://old.example',
        'previous_website_analysis' => wbsAnalysis('https://old.example', null, []),
    ]);

    Queue::assertNotPushed(AnalyzePreviousWebsiteJob::class);
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

    $analyzer = new WebsiteStyleAnalyzer(wbsFetcher([
        'https://site1.test' => wbsResponse('<html><head><style>body{background:#111}</style></head></html>', 'https://site1.test'),
        'https://store.test' => wbsResponse('<html><head><style>body{background:#f8f4ec}</style></head></html>', 'https://store.test'),
    ]), new MetadataParser);

    (new AnalyzeConnectionWebsitesJob((string) $user->id))->handle($analyzer);

    $link = IntegrationConnection::query()->find($linkId);
    $shop = IntegrationConnection::query()->find($shopId);
    expect($link->payload['styleAnalysis']['tiers']['bg'])->toBe('dark')
        ->and($shop->payload['b1']['styleAnalysis']['tiers']['bg'])->toBe('warm_light')
        ->and(AnalyzeConnectionWebsitesJob::connectionNeedsAnalyses($link->fresh()))->toBeFalse()
        ->and(AnalyzeConnectionWebsitesJob::connectionNeedsAnalyses($shop->fresh()))->toBeFalse();
});

// ── AnalyzePreviousWebsiteJob + logo grab ──────────────────────────────────

it('stores the analysis and auto-populates the empty square logo slot from a qualifying favicon', function () {
    Queue::fake();
    setupMediaTables();
    $user = createTenant('pw-job');
    wbsSeedWorkplace($user->site->id, 'https://old.example', null);

    $png = wbsPngBytes(200, 200);
    $html = '<html><head><link rel="apple-touch-icon" sizes="200x200" href="/icon.png"><style>body{background:#0e0e0e}</style></head></html>';
    $fetcher = wbsFetcher([
        'https://old.example' => wbsResponse($html, 'https://old.example'),
        'https://old.example/icon.png' => wbsResponse($png, 'https://old.example/icon.png', 'image/png'),
    ]);

    $uploads = Mockery::mock(MediaUploadService::class);
    $uploads->shouldReceive('uploadSingleton')->once()->withArgs(
        fn ($pro, $site, $file, $purpose) => $purpose === SiteMedia::PURPOSE_LOGO_SQUARE,
    )->andReturn(new SiteMedia);

    (new AnalyzePreviousWebsiteJob($user->site->id))->handle(
        new WebsiteStyleAnalyzer($fetcher, new MetadataParser),
        new LogoAutoGrabber($fetcher, $uploads),
    );

    $analysis = Workplace::query()->find($user->site->id)->previous_website_analysis;
    expect($analysis['ok'])->toBeTrue()->and($analysis['tiers']['bg'])->toBe('dark');
    Queue::assertPushed(ResolveDesignPresetsJob::class);
});

it('rejects a small favicon and a social-card-ratio og:image', function () {
    Queue::fake();
    setupMediaTables();
    $user = createTenant('logo-gates');
    wbsSeedWorkplace($user->site->id, 'https://gate.example', null);

    $tiny = wbsPngBytes(32, 32);
    $card = wbsPngBytes(1200, 630); // 1.90 ratio, no alpha → share card, not a logo
    $html = '<html><head><link rel="icon" sizes="32x32" href="/f.png"><meta property="og:image" content="/og.png"></head></html>';
    $fetcher = wbsFetcher([
        'https://gate.example' => wbsResponse($html, 'https://gate.example'),
        'https://gate.example/f.png' => wbsResponse($tiny, 'https://gate.example/f.png', 'image/png'),
        'https://gate.example/og.png' => wbsResponse($card, 'https://gate.example/og.png', 'image/png'),
    ]);

    $uploads = Mockery::mock(MediaUploadService::class);
    $uploads->shouldNotReceive('uploadSingleton');

    (new AnalyzePreviousWebsiteJob($user->site->id))->handle(
        new WebsiteStyleAnalyzer($fetcher, new MetadataParser),
        new LogoAutoGrabber($fetcher, $uploads),
    );

    expect(Workplace::query()->find($user->site->id)->previous_website_analysis['ok'])->toBeTrue();
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

/** Minimal in-memory PNG of the given dimensions (GD). */
function wbsPngBytes(int $w, int $h): string
{
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 200, 60, 30));
    ob_start();
    imagepng($img);

    return (string) ob_get_clean();
}
