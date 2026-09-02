<?php

use App\Models\Core\Site\Menu;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Content\ManualMenuWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
});

it('a manual-item write moves site.sites.updated_at (A.11 — the payload cache key)', function () {
    $user = User::factory()->create(['account_type' => 'business', 'sector' => 'restaurant']);
    $site = Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'bumptouch']);

    $stale = now()->subDay()->toDateTimeString();
    DB::connection('pgsql')->table('site.sites')->where('id', $site->id)->update(['updated_at' => $stale]);

    $menu = Menu::create(['user_id' => $user->id, 'content_source' => 'scan', 'currency' => 'AUD', 'fetch_status' => 'ok']);
    $writer = app(ManualMenuWriter::class);
    $writer->write(
        (string) $user->id,
        $writer->coordFor((string) $menu->id, 'Bump Dish'),
        $writer->projectionFor(
            (object) ['name' => 'Bump Dish', 'description' => null, 'base_price' => 5.0],
            [['id' => (string) Str::uuid(), 'name' => 'Mains', 'position' => 0]],
            [],
            $menu,
        ),
    );

    $after = DB::connection('pgsql')->table('site.sites')->where('id', $site->id)->value('updated_at');
    expect((string) $after)->not->toBe($stale);
});
