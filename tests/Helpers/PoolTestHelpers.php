<?php

use App\Http\Controllers\Api\Content\PoolController;
use App\Models\Core\Site\Site;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Content\ManualServiceWriter;
use App\Site\Pools\PoolSectionProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// The pool-lane fixture family. Lives here rather than inside PoolLaneTest.php
// because four other suites build pool fixtures too (the Media* trio and the
// owner-service callers of ownerServiceItem() below). A function declared inside a Pest test file only
// exists once PHPUnit has included THAT file — fine serially, where discovery
// includes every test file before any of them run, but under `--parallel` each
// worker includes only its own assigned files, so a caller that lands in a
// worker without PoolLaneTest.php fatals with "Call to undefined function".
// Pest.php require_once's this file, so every worker has it. Same reasoning as
// MigrationColumnReplay's move to PSR-4 (#FFLAG-1 / TESTFX-1) and
// seedUserWithSite()'s move into Pest.php.

if (! function_exists('poolTenant')) {
    function poolTenant(): array
    {
        $pro = createTenant('pool-'.Str::lower(Str::random(6)));
        $siteId = (string) DB::table('site.sites')->where('user_id', $pro->id)->value('id');

        return [$pro, $siteId];
    }
}

if (! function_exists('poolBusinessTenant')) {
    /**
     * A pool tenant flipped to the business account type. Venue-level reviews
     * shown WHOLESALE became business behaviour with the person-scoping
     * capability (2026-08-28) — review fixtures that model the venue case use
     * this instead of poolTenant().
     */
    function poolBusinessTenant(): array
    {
        [$pro, $siteId] = poolTenant();
        DB::table('core.users')->where('id', $pro->id)->update(['account_type' => 'business']);
        AccountCapabilities::flushCache();

        return [$pro->fresh(), $siteId];
    }
}

if (! function_exists('poolConnection')) {
    function poolConnection(string $userId, string $surfaceKey = 'youtube.channel', ?array $displaySettings = null): string
    {
        $id = (string) Str::uuid();
        DB::table('site.platform_connections')->insert([
            'id' => $id, 'user_id' => $userId, 'surface_key' => $surfaceKey,
            'routing_class' => 'content', 'resource_id' => 'acct-'.Str::random(6),
            'payload' => '{}', 'is_active' => 1,
            'display_settings' => $displaySettings === null ? null : json_encode($displaySettings),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}

if (! function_exists('poolSource')) {
    function poolSource(string $userId, ?string $connectionId): string
    {
        $id = (string) Str::uuid();
        DB::table('content.sources')->insert([
            'id' => $id, 'user_id' => $userId, 'kind' => $connectionId === null ? 'manual' : 'connection',
            'connection_id' => $connectionId, 'priority' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}

if (! function_exists('poolItem')) {
    function poolItem(string $userId, string $sourceId, string $kind, string $headline, string $publishedAt): string
    {
        $id = (string) Str::uuid();
        DB::table('content.items')->insert([
            'id' => $id, 'user_id' => $userId, 'kind' => $kind,
            'headline_cache' => $headline, 'facets_cache' => '[]',
            'first_seen_at' => now()->subDays(30), 'last_seen_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('content.source_items')->insert([
            'id' => (string) Str::uuid(), 'source_id' => $sourceId,
            'coord' => 'x:'.Str::random(8), 'item_id' => $id, 'kind' => $kind,
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        DB::table('content.f_published')->insert([
            'item_id' => $id, 'source_id' => $sourceId,
            'published_from' => $publishedAt, 'updated_at' => now(),
        ]);

        return $id;
    }
}

if (! function_exists('poolGet')) {
    function poolGet(object $pro, string $pool = 'watch'): array
    {
        $request = Request::create("/api/content/pools/{$pool}", 'GET');
        $request->attributes->set('professional', $pro);

        return app(PoolController::class)->show($request, $pool)->getData(true);
    }
}

if (! function_exists('poolHeadlines')) {
    function poolHeadlines(array $payload, string $key = 'selection'): array
    {
        return array_column($payload[$key] ?? [], 'headline');
    }
}

// ── Media frames (slice 1a) ─────────────────────────────────────────────────
// frameAsset is called from ShopPoolPayloadTest as well as MediaPoolFramesTest.

if (! function_exists('frameAsset')) {
    function frameAsset(string $userId, array $overrides = []): string
    {
        $id = (string) Str::uuid();
        DB::table('content.media_assets')->insert(array_merge([
            'id' => $id, 'user_id' => $userId, 'fingerprint' => 'url-'.sha1($id),
            'source_url' => null, 'storage_path' => null, 'site_media_id' => null,
            'width' => null, 'height' => null, 'created_at' => now(),
        ], $overrides));

        return $id;
    }
}

if (! function_exists('frameRow')) {
    function frameRow(string $itemId, string $sourceId, string $assetId, string $role, int $position, ?string $alt = null): void
    {
        DB::table('content.item_media')->insert([
            'id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $sourceId,
            'asset_id' => $assetId, 'role' => $role, 'position' => $position,
            'alt_text' => $alt, 'created_at' => now(),
        ]);
    }
}

// ── Shop pool (slice 5b) ────────────────────────────────────────────────────
// shopStore/shopProduct are called from ShopWireRetirementTest and
// PoolWireShapeTest as well as ShopPoolPayloadTest.

if (! function_exists('shopStore')) {
    function shopStore(string $userId, array $overrides = []): string
    {
        $collectionId = (string) Str::uuid();
        DB::table('content.collections')->insert([
            'id' => $collectionId, 'user_id' => $userId, 'kind' => 'storefront',
            'label' => $overrides['label'] ?? 'Test Store', 'is_user_created' => false,
            'position' => $overrides['position'] ?? 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('content.storefronts')->insert(array_merge([
            // NOT NULL in production since 20260819000100, and the pool payload
            // now pins it on the join (#W1-SEC-10) — a fixture that omits it
            // models a row the schema cannot hold and silently drops the store
            // card. The SQLite stand-in leaves the column nullable, so only
            // this line keeps the fixture production-shaped.
            'user_id' => $userId,
            'collection_id' => $collectionId, 'external_ref' => 'ext-'.Str::random(6),
            'provider' => 'shopify', 'url' => 'https://store.example.com',
            'currency' => 'AUD', 'discount_code' => null, 'referral_query' => '',
            'is_individual' => false, 'logo_url' => 'https://cdn.example.com/logo.png',
            'favicon_url' => 'https://cdn.example.com/fav.ico',
            'created_at' => now(), 'updated_at' => now(),
        ], array_diff_key($overrides, ['label' => 1, 'position' => 1])));

        return $collectionId;
    }
}

if (! function_exists('shopProduct')) {
    function shopProduct(string $userId, string $collectionId, string $title, int $position = 0): string
    {
        // Reuse the user's manual source if one exists: idx_content_sources_manual
        // (20260727140000) allows exactly ONE manual source per user, so a second
        // poolSource($userId, null) is a unique violation, not a second store.
        $sourceId = DB::table('content.sources')
            ->where('user_id', $userId)->where('kind', 'manual')->value('id')
            ?? poolSource($userId, null);
        $itemId = poolItem($userId, $sourceId, 'product', $title, '2026-08-01T00:00:00Z');
        DB::table('content.collection_items')->insert([
            'collection_id' => $collectionId, 'item_id' => $itemId,
            'source_id' => null, 'position' => $position,
        ]);
        DB::table('content.f_link')->insert([
            'item_id' => $itemId, 'source_id' => $sourceId,
            'url' => 'https://store.example.com/products/'.Str::slug($title), 'updated_at' => now(),
        ]);
        DB::table('content.f_catalog')->insert([
            'item_id' => $itemId, 'source_id' => $sourceId,
            'handle' => Str::slug($title), 'vendor' => 'A Vendor',
            'variant_ref' => '44073715368070', 'updated_at' => now(),
        ]);
        DB::table('content.f_text')->insert([
            'item_id' => $itemId, 'source_id' => $sourceId,
            'headline' => $title, 'body' => 'A description.', 'updated_at' => now(),
        ]);

        return $itemId;
    }
}

if (! function_exists('ownerServiceItem')) {
    /**
     * ONE owner-authored service, landed as a content item through the real
     * manual lane. Returns the content.items id.
     *
     * Replaces the `ownerService() + ServiceBackfiller::run()` pair every
     * service fixture used to build. That pair wrote a `site.services` row and
     * migrated it; the services cutover dropped that table and retired the
     * backfiller, so the item is written directly through the same collaborator
     * the backfiller itself used — `ManualServiceWriter` — and this reproduces
     * its per-row behaviour exactly:
     *
     *   * coord `manual:{uuid}` (the backfiller keyed on the legacy row's id;
     *     any stable uuid does the same job now that no legacy id exists),
     *   * `deleted_at` -> `markRemoved()` on items.removed_at ONLY, never
     *     source_items.removed_at,
     *   * `is_active` false -> a pool EXCLUDE, not a pin, because a
     *     live-but-hidden service must not surface in buildServicesData(),
     *   * otherwise a pin at `sort_order` — which is what keeps the ordering
     *     assertions in the public-read tests meaningful.
     *
     * Defaults mirror the retired ownerService() so re-fixtured callers keep
     * their existing assertions.
     *
     * @param  array<string, mixed>  $attrs  title, description, price_cents,
     *                                       currency_code, duration_minutes,
     *                                       sort_order, is_active, deleted_at
     */
    function ownerServiceItem(string $userId, array $attrs = []): string
    {
        $attrs = array_merge([
            'title' => 'Consultation',
            'description' => 'A chat about your hair.',
            'price_cents' => 6500,
            'currency_code' => 'AUD',
            'duration_minutes' => 45,
            'sort_order' => 0,
            'is_active' => true,
            'deleted_at' => null,
        ], $attrs);

        $writer = app(ManualServiceWriter::class);

        $itemId = $writer->write($userId, 'manual:'.(string) Str::uuid(), $writer->projectionFor((object) [
            'title' => $attrs['title'],
            'description' => $attrs['description'],
            'price_cents' => $attrs['price_cents'],
            'currency_code' => $attrs['currency_code'],
            'duration_minutes' => $attrs['duration_minutes'],
        ]));

        if ($attrs['deleted_at'] !== null) {
            $writer->markRemoved($itemId, $attrs['deleted_at']);

            return $itemId;
        }

        $site = Site::query()->where('user_id', $userId)->first();
        if ($site !== null) {
            (bool) $attrs['is_active']
                ? $writer->pin($site, $itemId, (float) $attrs['sort_order'])
                : $writer->exclude($site, $itemId);
        }

        return $itemId;
    }
}

if (! function_exists('poolPin')) {
    /**
     * Pin one item into a site's pool section — the owner's "put it on the
     * site" (2026-08-17). Shop is opt-in now (pins + latest-per-source), so a
     * shopProduct() fixture is in the LIBRARY only until it is pinned; the
     * payload-contract tests pin theirs to keep asserting the selection wire.
     */
    function poolPin(string $siteId, string $pool, string $itemId): void
    {
        $site = Site::query()->findOrFail($siteId);
        $section = app(PoolSectionProvisioner::class)->ensure($site, $pool);
        DB::connection('pgsql')->table('site.section_items')->insert([
            'id' => (string) Str::uuid(),
            'section_id' => (string) $section->id,
            'item_id' => $itemId,
            'state' => 'pinned',
            'sort_key' => 1,
            'created_at' => now(),
        ]);
    }
}

if (! function_exists('poolOrderMode')) {
    /** settings.pool_order[pool] — the per-pool ordering mode (2026-08-23). */
    function poolOrderMode(string $siteId, string $pool, string $mode): void
    {
        $raw = DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('settings');
        $settings = is_string($raw) ? (array) json_decode($raw, true) : [];
        $settings['pool_order'] = array_merge((array) ($settings['pool_order'] ?? []), [$pool => $mode]);
        DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->update(['settings' => json_encode($settings)]);
    }
}
