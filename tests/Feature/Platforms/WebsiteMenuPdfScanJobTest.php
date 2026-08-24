<?php

use App\Jobs\Platforms\WebsiteMenuPdfScanJob;
use App\Models\Core\Site\Menu;
use App\Models\Core\User\User;
use App\Services\Content\ManualMenuItems;
use App\Services\Platforms\MenuAiExtractor;
use App\Services\Platforms\MenuScanApplier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    Queue::fake();
});

function wmpsjUser(string $h, string $accountType = 'business', string $sector = 'restaurant'): User
{
    return User::create([
        'handle' => $h, 'handle_lc' => strtolower($h), 'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => $accountType, 'sector' => $sector,
        'auth_user_id' => (string) Str::uuid(), 'primary_email' => "{$h}@example.com",
    ]);
}

it('OCRs, structures, and applies a website PDF menu, tagged website-scan', function () {
    config()->set('services.mistral.key', 'k1');
    config()->set('services.deepseek.key', 'k2');

    $user = wmpsjUser('wmpsj1');
    $menuText = str_repeat('PIZZA MARGHERITA 18.00 san marzano tomato basil ', 8);

    Http::fake([
        'api.mistral.ai/v1/ocr' => Http::response(['pages' => [['markdown' => $menuText]]]),
        'api.deepseek.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode(['items' => [
            ['name' => 'Pizza Margherita', 'description' => 'San Marzano tomato, basil.', 'price' => 18.0, 'category' => 'Pizza', 'dietary' => null],
        ]])]]]]),
    ]);

    (new WebsiteMenuPdfScanJob((string) $user->id, 'https://venue.example/menu.pdf'))
        ->handle(app(MenuAiExtractor::class), app(MenuScanApplier::class));

    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();
    $items = app(ManualMenuItems::class);
    $row = $items->rows((string) $user->id)->firstWhere('headline', 'Pizza Margherita');

    expect($row)->not->toBeNull()
        ->and($row->description)->toBe('San Marzano tomato, basil.')
        ->and((string) $items->categories((string) $user->id)[0]->external_ref)
        ->toBe(MenuScanApplier::categoryRefFor('website-scan', 'Pizza'))
        ->and($menu->content_source)->toBe('website-scan');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.mistral.ai')
        && ($request['document']['document_url'] ?? null) === 'https://venue.example/menu.pdf');
});

it('spends nothing for accounts without the menu capability', function () {
    Http::fake();
    config()->set('services.mistral.key', 'k1');
    config()->set('services.deepseek.key', 'k2');

    $user = wmpsjUser('wmpsjnocap', 'partna'); // partna accounts never have can_use_menu

    (new WebsiteMenuPdfScanJob((string) $user->id, 'https://venue.example/menu.pdf'))
        ->handle(app(MenuAiExtractor::class), app(MenuScanApplier::class));

    Http::assertNothingSent();
});

it('spends nothing when the AI keys are not configured', function () {
    Http::fake();
    config()->set('services.mistral.key', '');
    config()->set('services.deepseek.key', '');

    $user = wmpsjUser('wmpsjnokeys');

    (new WebsiteMenuPdfScanJob((string) $user->id, 'https://venue.example/menu.pdf'))
        ->handle(app(MenuAiExtractor::class), app(MenuScanApplier::class));

    Http::assertNothingSent();
});

it('does nothing when OCR reads no text', function () {
    config()->set('services.mistral.key', 'k1');
    config()->set('services.deepseek.key', 'k2');
    Http::fake(['api.mistral.ai/v1/ocr' => Http::response(['pages' => []])]);

    $user = wmpsjUser('wmpsjempty');

    (new WebsiteMenuPdfScanJob((string) $user->id, 'https://venue.example/menu.pdf'))
        ->handle(app(MenuAiExtractor::class), app(MenuScanApplier::class));

    expect(Menu::query()->where('user_id', $user->id)->exists())->toBeFalse();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.deepseek.com'));
});

it('does nothing when the user no longer exists', function () {
    Http::fake();
    (new WebsiteMenuPdfScanJob((string) Str::uuid(), 'https://venue.example/menu.pdf'))
        ->handle(app(MenuAiExtractor::class), app(MenuScanApplier::class));

    Http::assertNothingSent();
});
