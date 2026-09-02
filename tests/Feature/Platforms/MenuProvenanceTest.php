<?php

use App\Models\Core\Site\Menu;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Content\ManualMenuWriter;
use App\Services\Platforms\MenuPayloadComposer;
use App\Services\Platforms\MenuScanApplier;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
});

function mprovUser(string $h): User
{
    $user = User::factory()->create([
        'handle' => $h, 'handle_lc' => strtolower($h),
        'account_type' => 'business', 'sector' => 'restaurant',
    ]);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => $h]);

    return $user;
}

/** A scraped dish: its category carries a plain `menu:<slug>` ref (scraper-owned). */
function mprovScrapedDish(User $user, Menu $menu, string $name): void
{
    $writer = app(ManualMenuWriter::class);
    $writer->write(
        (string) $user->id,
        $writer->coordFor((string) $menu->id, $name),
        $writer->projectionFor(
            (object) ['name' => $name, 'description' => 'Scraped.', 'base_price' => 12.0],
            [['id' => (string) Str::uuid(), 'name' => 'Mains', 'position' => 0]],
            [],
            $menu,
        ),
    );
}

it('stamps provenance per dish from the category ref namespace (decision 12)', function () {
    $user = mprovUser('mprov');
    $menu = Menu::create(['user_id' => $user->id, 'content_source' => 'uber-eats', 'currency' => 'AUD', 'fetch_status' => 'ok']);

    mprovScrapedDish($user, $menu, 'Platform Pizza');
    app(MenuScanApplier::class)->apply($user, [
        ['name' => 'Scan Special', 'description' => 'From the photo scan.', 'price' => 9.5, 'category' => 'Specials'],
    ], enrichOnly: true);

    $payload = app(MenuPayloadComposer::class)->compose($user, $menu->fresh());

    $byName = [];
    foreach ($payload['categories'] as $category) {
        foreach ($category['items'] as $item) {
            $byName[$item['name']] = $item['provenance'];
        }
    }

    expect($byName['Platform Pizza'])->toBe('platform')
        ->and($byName['Scan Special'])->toBe('scan');
});

it('a website-scan dish reads website-scan, never folded into scan', function () {
    $user = mprovUser('mprovweb');
    $menu = Menu::create(['user_id' => $user->id, 'content_source' => 'website-scan', 'currency' => 'AUD', 'fetch_status' => 'ok']);

    app(MenuScanApplier::class)->apply($user, [
        ['name' => 'Site Find', 'description' => null, 'price' => null, 'category' => 'Menu Board'],
    ], enrichOnly: true, source: 'website-scan');

    $payload = app(MenuPayloadComposer::class)->compose($user, $menu->fresh());

    $provenance = null;
    foreach ($payload['categories'] as $category) {
        foreach ($category['items'] as $item) {
            if ($item['name'] === 'Site Find') {
                $provenance = $item['provenance'];
            }
        }
    }

    expect($provenance)->toBe('website-scan');
});
