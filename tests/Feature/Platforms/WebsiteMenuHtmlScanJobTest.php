<?php

use App\Jobs\Platforms\WebsiteMenuHtmlScanJob;
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
    setupItemSlugsTable();
    setupContentTables();
    Queue::fake();
});

function wmhsjUser(string $h, string $accountType = 'business', string $sector = 'restaurant'): User
{
    return User::create([
        'handle' => $h, 'handle_lc' => strtolower($h), 'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => $accountType, 'sector' => $sector,
        'auth_user_id' => (string) Str::uuid(), 'primary_email' => "{$h}@example.com",
    ]);
}

it('structures and applies an already-extracted HTML menu text, tagged website-scan', function () {
    config()->set('services.mistral.key', 'k1');
    config()->set('services.deepseek.key', 'k2');

    $user = wmhsjUser('wmhsj1');

    Http::fake([
        'api.deepseek.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode(['items' => [
            ['name' => 'Negroni', 'description' => 'Gin, Campari, vermouth.', 'price' => 14.0, 'category' => 'Cocktails', 'dietary' => null],
        ]])]]]]),
    ]);

    (new WebsiteMenuHtmlScanJob((string) $user->id, "Cocktails\nNegroni\n14"))
        ->handle(app(MenuAiExtractor::class), app(MenuScanApplier::class));

    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();
    $items = app(ManualMenuItems::class);
    $row = $items->rows((string) $user->id)->firstWhere('headline', 'Negroni');

    expect($row)->not->toBeNull()
        ->and($row->description)->toBe('Gin, Campari, vermouth.')
        // The 'website-scan' tag now lives in the category's external_ref
        // namespace, which is what keeps it out of a scraped rebuild's reach.
        ->and((string) $items->categories((string) $user->id)[0]->external_ref)
        ->toBe(MenuScanApplier::categoryRefFor('website-scan', 'Cocktails'))
        ->and($menu->content_source)->toBe('website-scan');

    // No OCR step for this path — text is already plain, never touches Mistral.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.mistral.ai'));
});

it('spends nothing for accounts without the menu capability', function () {
    Http::fake();
    config()->set('services.mistral.key', 'k1');
    config()->set('services.deepseek.key', 'k2');

    $user = wmhsjUser('wmhsjnocap', 'partna');

    (new WebsiteMenuHtmlScanJob((string) $user->id, 'Negroni 14'))
        ->handle(app(MenuAiExtractor::class), app(MenuScanApplier::class));

    Http::assertNothingSent();
});

it('spends nothing when the AI keys are not configured', function () {
    Http::fake();
    config()->set('services.mistral.key', '');
    config()->set('services.deepseek.key', '');

    $user = wmhsjUser('wmhsjnokeys');

    (new WebsiteMenuHtmlScanJob((string) $user->id, 'Negroni 14'))
        ->handle(app(MenuAiExtractor::class), app(MenuScanApplier::class));

    Http::assertNothingSent();
});

it('does nothing when structuring returns no items', function () {
    config()->set('services.mistral.key', 'k1');
    config()->set('services.deepseek.key', 'k2');
    Http::fake(['api.deepseek.com/*' => Http::response(['choices' => [['message' => ['content' => '{"items":[]}']]]])]);

    $user = wmhsjUser('wmhsjempty');

    (new WebsiteMenuHtmlScanJob((string) $user->id, 'Not actually a menu'))
        ->handle(app(MenuAiExtractor::class), app(MenuScanApplier::class));

    expect(Menu::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('does nothing when the user no longer exists', function () {
    Http::fake();
    (new WebsiteMenuHtmlScanJob((string) Str::uuid(), 'Negroni 14'))
        ->handle(app(MenuAiExtractor::class), app(MenuScanApplier::class));

    Http::assertNothingSent();
});
