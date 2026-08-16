<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\ShopBrand;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Content\LinkPoolReader;
use App\Services\Shop\ShopConnections;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Convergence Phase 6 unit 5: `content:retire-pseudo-platforms`.
//
// Every fixture here is a RAW INSERT, deliberately. A pre-migration row is
// exactly a row that predates the model guard, and IntegrationConnection's
// booted() hook now refuses to CREATE one — so building the pre-state through
// the model is not just awkward, it is impossible, which is the guard working.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
});

function retireUser(string $h): User
{
    $user = User::create([
        'handle' => $h, 'handle_lc' => strtolower($h), 'display_name' => ucfirst($h),
        'first_name' => ucfirst($h), 'account_type' => 'business', 'sector' => 'restaurant',
        'auth_user_id' => (string) Str::uuid(), 'primary_email' => "{$h}@example.com",
    ]);

    $site = new Site(['subdomain' => $h, 'is_published' => true, 'settings' => []]);
    $site->user()->associate($user);
    $site->save();

    return $user->refresh();
}

/** A pre-migration connection, inserted below the model layer. */
function legacyConnection(User $user, string $surface, string $routingClass, string $resourceId, array $payload): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => $id, 'user_id' => $user->id, 'surface_key' => $surface,
        'routing_class' => $routingClass, 'resource_id' => $resourceId,
        'payload' => json_encode($payload), 'is_active' => 1,
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);

    return $id;
}

it('repoints an ordering row whose host names a brand, keeping its resource id', function () {
    $user = retireUser('retire1');
    $url = 'https://www.ubereats.com/au/store/ollies/abc';
    $rid = 'order-'.substr(sha1(strtolower($url)), 0, 16);
    legacyConnection($user, 'partna.order_link', 'ordering', $rid, ['url' => $url, 'provider' => 'custom']);

    $this->artisan('content:retire-pseudo-platforms')->assertSuccessful();

    $row = IntegrationConnection::where('user_id', $user->id)->firstOrFail();
    expect($row->surface_key)->toBe('uber_eats.order');
    expect($row->routing_class)->toBe('ordering');
    // Load-bearing: SiteActionsService emits `ordering:<resource_id>` action ids
    // that owners store display preferences against.
    expect($row->resource_id)->toBe($rid);
    expect($row->trashed())->toBeFalse();
});

it('pools an ordering row with no brand home and retires the connection', function () {
    $user = retireUser('retire2');
    legacyConnection($user, 'partna.order_link', 'ordering', 'order-x', [
        'url' => 'https://hungryjacks.app.link/3FB5eplr3Kb', 'provider' => 'custom', 'name' => 'app.link',
    ]);

    $this->artisan('content:retire-pseudo-platforms')->assertSuccessful();

    expect(IntegrationConnection::where('user_id', $user->id)->exists())->toBeFalse();
    $cards = app(LinkPoolReader::class)->cards($user);
    expect($cards)->toHaveCount(1);
    expect($cards[0]['url'])->toBe('https://hungryjacks.app.link/3FB5eplr3Kb');
    expect($cards[0]['name'])->toBe('app.link');
});

it('pools a SECOND store on a brand already occupied (owner ruling 1)', function () {
    $user = retireUser('retire3');
    // The brand's slot is already taken by a different store.
    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $user->id, 'surface_key' => 'uber_eats.order',
        'routing_class' => 'ordering', 'resource_id' => 'order-first',
        'payload' => json_encode(['url' => 'https://www.ubereats.com/au/store/universal/aaa']),
        'is_active' => 1, 'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);
    legacyConnection($user, 'partna.order_link', 'ordering', 'order-second', [
        'url' => 'https://www.ubereats.com/au/store/doc-pizza/bbb', 'provider' => 'custom',
    ]);

    $this->artisan('content:retire-pseudo-platforms')->assertSuccessful();

    // The incumbent survives untouched; the second store became a link.
    expect(IntegrationConnection::where('user_id', $user->id)->pluck('resource_id')->all())->toBe(['order-first']);
    expect(app(LinkPoolReader::class)->cards($user))->toHaveCount(1);
});

it('pools both reservation rows (rulings 2 and 3)', function () {
    $user = retireUser('retire4');
    legacyConnection($user, 'partna.reserve_link', 'reservations', 'reservations', [
        'url' => 'https://www.sevenrooms.com/explore/maha/reservations/create/search',
        'provider' => 'custom', 'name' => 'SevenRooms',
    ]);

    $this->artisan('content:retire-pseudo-platforms')->assertSuccessful();

    expect(IntegrationConnection::where('user_id', $user->id)->exists())->toBeFalse();
    expect(app(LinkPoolReader::class)->cards($user))->toHaveCount(1);
});

it('repoints every store off a shop marker onto its own anchor, then retires the marker', function () {
    $user = retireUser('retire5');
    $marker = legacyConnection($user, 'partna.storefront', 'shop', 'shop', ['storage' => 'relational']);

    foreach ([['a-store', 'shopify'], ['b-store', 'woocommerce']] as [$brandId, $provider]) {
        ShopBrand::create([
            'connection_id' => $marker, 'brand_id' => $brandId, 'provider' => $provider,
            'url' => "https://{$brandId}.example", 'source_url' => "https://{$brandId}.example",
            'discount_code' => '', 'referral_query' => '', 'is_individual' => false, 'position' => 0,
        ]);
    }

    $this->artisan('content:retire-pseudo-platforms')->assertSuccessful();

    // One connection per store, each on its provider's real surface.
    $surfaces = IntegrationConnection::where('user_id', $user->id)
        ->pluck('surface_key', 'resource_id')->all();
    expect($surfaces)->toBe(['a-store' => 'shopify.store', 'b-store' => 'woocommerce.store']);

    // And every brand row followed its store, so no read is orphaned.
    expect(app(ShopConnections::class)->brands($user->fresh())->count())->toBe(2);
    expect(IntegrationConnection::withTrashed()->find($marker)->trashed())->toBeTrue();
});

it('retires an EMPTY storefront marker outright', function () {
    $user = retireUser('retire6');
    legacyConnection($user, 'partna.storefront', 'shop', 'shop', ['storage' => 'relational']);

    $this->artisan('content:retire-pseudo-platforms')->assertSuccessful();

    expect(IntegrationConnection::where('user_id', $user->id)->exists())->toBeFalse();
});

it('retires a custom_link row without writing anything — Phase 3 already pooled it', function () {
    $user = retireUser('retire7');
    legacyConnection($user, 'partna.custom_link', 'link', 'link-abc', [
        'url' => 'https://example.com/one', 'name' => 'Example',
    ]);

    $this->artisan('content:retire-pseudo-platforms')->assertSuccessful();

    expect(IntegrationConnection::where('user_id', $user->id)->exists())->toBeFalse();
    // No pool item minted here: the backfill did that, and re-minting would
    // resurrect a link the owner may have since removed.
    expect(app(LinkPoolReader::class)->cards($user))->toHaveCount(0);
});

it('is idempotent — a second run changes nothing', function () {
    $user = retireUser('retire8');
    $url = 'https://www.ubereats.com/au/store/ollies/abc';
    legacyConnection($user, 'partna.order_link', 'ordering', 'order-a', ['url' => $url]);
    legacyConnection($user, 'partna.reserve_link', 'reservations', 'reservations', [
        'url' => 'https://example.com/book', 'name' => 'Book',
    ]);

    $this->artisan('content:retire-pseudo-platforms')->assertSuccessful();

    $after = IntegrationConnection::where('user_id', $user->id)->get()
        ->map(fn ($r) => $r->surface_key.'|'.$r->resource_id)->sort()->values()->all();
    $links = app(LinkPoolReader::class)->cards($user);

    $this->artisan('content:retire-pseudo-platforms')->assertSuccessful();

    expect(IntegrationConnection::where('user_id', $user->id)->get()
        ->map(fn ($r) => $r->surface_key.'|'.$r->resource_id)->sort()->values()->all())->toBe($after);
    expect(app(LinkPoolReader::class)->cards($user))->toHaveCount(count($links));
});

it('--dry-run writes nothing', function () {
    $user = retireUser('retire9');
    legacyConnection($user, 'partna.order_link', 'ordering', 'order-a', [
        'url' => 'https://www.ubereats.com/au/store/ollies/abc',
    ]);

    $this->artisan('content:retire-pseudo-platforms', ['--dry-run' => true])->assertSuccessful();

    $row = IntegrationConnection::where('user_id', $user->id)->firstOrFail();
    expect($row->surface_key)->toBe('partna.order_link');
    expect($row->trashed())->toBeFalse();
});

it('leaves a siteless owner\'s row LIVE rather than destroying content it cannot preserve', function () {
    // No site → no section → no pool item. Retiring the row anyway would delete
    // the only copy of the link.
    $user = User::create([
        'handle' => 'retire10', 'handle_lc' => 'retire10', 'display_name' => 'Retire10',
        'first_name' => 'Retire10', 'account_type' => 'business',
        'auth_user_id' => (string) Str::uuid(), 'primary_email' => 'retire10@example.com',
    ]);
    legacyConnection($user, 'partna.reserve_link', 'reservations', 'reservations', [
        'url' => 'https://example.com/book',
    ]);

    // Non-zero exit: a partial run must not read as a clean one to a deploy script.
    $this->artisan('content:retire-pseudo-platforms')->assertFailed();

    expect(IntegrationConnection::where('user_id', $user->id)->exists())->toBeTrue();
});
