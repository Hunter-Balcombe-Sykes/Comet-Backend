<?php

use App\Jobs\Platforms\GoogleMenuPhotoScanJob;
use App\Jobs\Platforms\MenuPhotoSweepJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\Content\ManualMenuItems;
use App\Services\Platforms\GoogleMenuImagesScraper;
use App\Services\Platforms\MenuAiExtractor;
use App\Services\Platforms\MenuScanApplier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    setupPreAccountBuildsTable();
    Queue::fake();

    config()->set('services.mistral.key', 'k1');
    config()->set('services.deepseek.key', 'k2');
    config()->set('services.apify.token', 'apify-token'); // tier 2 IS available — the gate is what defers it
});

function msdUser(string $h, string $status = 'active'): User
{
    $user = User::factory()->create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'account_type' => 'business',
        'sector' => 'restaurant',
        'status' => $status,
        'auth_user_id' => $status === 'unclaimed' ? null : (string) Str::uuid(),
        'primary_email' => $status === 'unclaimed' ? null : "{$h}@example.com",
    ]);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => $h]);

    return $user;
}

function msdSignupBuild(User $user): void
{
    $build = PreAccountBuild::factory()->make(['source_type' => 'instagram']); // factory defaults built_via => signup
    $build->user()->associate($user);
    $build->save();
}

function msdGbpConnection(User $user): void
{
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'google-business',
        'resource_id' => 'place-msd',
        'payload' => [
            'placeId' => 'place-msd',
            'photos' => [['ref' => 'p/1', 'url' => 'https://lh3.example.com/photo.jpg']],
        ],
    ]);
}

it('skips the paid tier-2 sweep on a sign-up build (tier 1 only)', function () {
    $user = msdUser('msddefer', 'unclaimed');
    msdSignupBuild($user);
    msdGbpConnection($user);

    // Tier 1 OCR reads nothing menu-dense — exactly the state that triggers
    // the paid sweep for everyone else.
    Http::fake(['api.mistral.ai/v1/ocr' => Http::response(['pages' => [['markdown' => 'latte art']]])]);

    $scraper = Mockery::mock(GoogleMenuImagesScraper::class);
    $scraper->shouldNotReceive('fetch');

    (new GoogleMenuPhotoScanJob((string) $user->id, 'place-msd'))->handle(
        app(MenuAiExtractor::class),
        $scraper,
        app(MenuScanApplier::class),
    );

    expect(app(ManualMenuItems::class)->rows((string) $user->id))->toHaveCount(0);
});

it('still sweeps tier 2 for a claimed user in the same dense-less state', function () {
    $user = msdUser('msdclaimed');
    msdGbpConnection($user);

    Http::fake(['api.mistral.ai/v1/ocr' => Http::response(['pages' => [['markdown' => 'latte art']]])]);

    $scraper = Mockery::mock(GoogleMenuImagesScraper::class);
    $scraper->shouldReceive('fetch')->once()->andReturn([]);

    (new GoogleMenuPhotoScanJob((string) $user->id, 'place-msd'))->handle(
        app(MenuAiExtractor::class),
        $scraper,
        app(MenuScanApplier::class),
    );
});

it('MenuPhotoSweepJob runs the sweep and applies the structured items', function () {
    $user = msdUser('msdsweep');
    msdGbpConnection($user);

    $menuText = str_repeat('PIZZA MARGHERITA 25.5 san marzano tomato basil ', 8);
    Http::fake([
        'api.mistral.ai/v1/ocr' => Http::response(['pages' => [['markdown' => $menuText]]]),
        'api.deepseek.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode(['items' => [
                ['name' => 'Pizza Margherita', 'description' => 'Wood fired.', 'price' => 25.5, 'category' => 'Pizza'],
            ]])]]],
        ]),
    ]);

    $scraper = Mockery::mock(GoogleMenuImagesScraper::class);
    $scraper->shouldReceive('fetch')->once()->with('place-msd', (string) $user->id)
        ->andReturn(['https://lh3.example.com/sweep-board.jpg']);

    (new MenuPhotoSweepJob((string) $user->id))->handle(
        app(MenuAiExtractor::class),
        $scraper,
        app(MenuScanApplier::class),
    );

    $rows = app(ManualMenuItems::class)->rows((string) $user->id);
    expect($rows)->toHaveCount(1)
        ->and((string) $rows->first()->headline)->toBe('Pizza Margherita');
});

it('MenuPhotoSweepJob spends nothing when an ordering connection exists by run time', function () {
    $user = msdUser('msdordered');
    msdGbpConnection($user);
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'uber-eats',
        'surface_key' => 'uber_eats.order',
        'routing_class' => 'ordering',
        'resource_id' => 'store-1',
    ]);

    $scraper = Mockery::mock(GoogleMenuImagesScraper::class);
    $scraper->shouldNotReceive('fetch');

    (new MenuPhotoSweepJob((string) $user->id))->handle(
        app(MenuAiExtractor::class),
        $scraper,
        app(MenuScanApplier::class),
    );
});
