<?php

/** @phpstan-ignore-all */

use App\Models\Core\Professional\BrandPartnerLink;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Verifies the brand_links notification category fires from
// BrandPartnerLinkObserver on create + delete, to BOTH affiliate and brand,
// and dedupes correctly. Mirrors the in-memory SQLite + ATTACH SCHEMA pattern
// used elsewhere in tests/Feature/Notifications.
beforeEach(function () {
    Config::set('database.connections.pgsql', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);

    DB::purge('pgsql');
    DB::reconnect('pgsql');

    $conn = DB::connection('pgsql');

    foreach (['core', 'brand', 'notifications', 'site'] as $schema) {
        try {
            $conn->statement("ATTACH DATABASE ':memory:' AS {$schema}");
        } catch (\Throwable) {
        }
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS core.professionals (
        id TEXT PRIMARY KEY,
        handle TEXT NULL,
        display_name TEXT NULL,
        has_historical_partner_links INTEGER NULL,
        deleted_at TEXT NULL
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS brand.brand_partner_links (
        id TEXT PRIMARY KEY,
        affiliate_professional_id TEXT NOT NULL,
        brand_professional_id TEXT NOT NULL,
        slot INTEGER NULL DEFAULT 0,
        custom_photos_enabled INTEGER NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL,
        deleted_at TEXT NULL
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS notifications.notifications (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        type TEXT NOT NULL,
        category TEXT NOT NULL,
        title TEXT NOT NULL,
        body TEXT NOT NULL,
        cta_url TEXT NULL,
        primary_action_label TEXT NULL,
        secondary_action_label TEXT NULL,
        secondary_action_url TEXT NULL,
        severity TEXT NULL,
        starts_at TEXT NULL,
        ends_at TEXT NULL,
        dedupe_key TEXT NULL,
        email_sent_at TEXT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');

    $conn->statement(
        'CREATE UNIQUE INDEX IF NOT EXISTS notifications.notifications_dedupe_key_per_pro_uq
         ON notifications (professional_id, dedupe_key)
         WHERE dedupe_key IS NOT NULL'
    );

    // Site table — observer touches Site::query() when busting Hydrogen cache.
    $conn->statement('CREATE TABLE IF NOT EXISTS site.sites (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL
    )');

    Config::set('partna.notifications.email_enabled', false);

    // Reset the observer's static name cache so memoization doesn't bleed
    // across tests when the same id happens to be reused.
    $ref = new \ReflectionClass(\App\Observers\Core\BrandPartnerLinkObserver::class);
    if ($ref->hasProperty('nameCache')) {
        $prop = $ref->getProperty('nameCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    // Don't actually dispatch the Cloudflare KV sync job from the observer.
    Queue::fake();
});

function brandLink_seedProfessional(string $displayName, string $handle): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => $handle,
        'display_name' => $displayName,
    ]);

    return $id;
}

it('publishes brand_links notifications to both affiliate and brand on create', function () {
    $affiliateId = brandLink_seedProfessional('Alice Affiliate', 'alice');
    $brandId = brandLink_seedProfessional('Acme Brand', 'acme');

    BrandPartnerLink::create([
        'affiliate_professional_id' => $affiliateId,
        'brand_professional_id' => $brandId,
        'slot' => 0,
    ]);

    $rows = DB::table('notifications.notifications')
        ->where('category', 'brand_links')
        ->get();

    expect($rows)->toHaveCount(2);

    $byPro = $rows->keyBy('professional_id');
    expect($byPro)->toHaveKey($affiliateId);
    expect($byPro)->toHaveKey($brandId);

    expect($byPro[$affiliateId]->dedupe_key)->toBe("brand_link.created.{$affiliateId}.{$brandId}");
    expect($byPro[$brandId]->dedupe_key)->toBe("brand_link.created.{$affiliateId}.{$brandId}");

    expect($byPro[$affiliateId]->body)->toContain('Acme Brand');
    expect($byPro[$brandId]->body)->toContain('Alice Affiliate');

    expect($byPro[$affiliateId]->cta_url)->toBe('/account/affiliates');
});

it('publishes brand_links notifications to both sides on delete', function () {
    $affiliateId = brandLink_seedProfessional('Bob Affiliate', 'bob');
    $brandId = brandLink_seedProfessional('Beta Brand', 'beta');

    $link = BrandPartnerLink::create([
        'affiliate_professional_id' => $affiliateId,
        'brand_professional_id' => $brandId,
        'slot' => 0,
    ]);

    // Clear the created notifications so we only inspect the delete output.
    DB::table('notifications.notifications')->delete();

    $linkId = $link->id;
    $link->delete();

    $rows = DB::table('notifications.notifications')
        ->where('category', 'brand_links')
        ->get();

    expect($rows)->toHaveCount(2);

    $byPro = $rows->keyBy('professional_id');
    expect($byPro)->toHaveKey($affiliateId);
    expect($byPro)->toHaveKey($brandId);

    expect($byPro[$affiliateId]->dedupe_key)->toBe("brand_link.removed.{$linkId}");
    expect($byPro[$brandId]->dedupe_key)->toBe("brand_link.removed.{$linkId}");

    expect($byPro[$affiliateId]->body)->toContain('Beta Brand');
    expect($byPro[$affiliateId]->body)->toContain('removed');
    expect($byPro[$brandId]->body)->toContain('Bob Affiliate');
});

it('dedupes duplicate created publishes for the same affiliate-brand pair', function () {
    $affiliateId = brandLink_seedProfessional('Carol Affiliate', 'carol');
    $brandId = brandLink_seedProfessional('Cee Brand', 'cee');

    BrandPartnerLink::create([
        'affiliate_professional_id' => $affiliateId,
        'brand_professional_id' => $brandId,
        'slot' => 0,
    ]);

    // Second link with same pair — would happen if the link were recreated
    // within the retention window. The dedupe_key is pair-based, so the
    // notification rows must not duplicate.
    BrandPartnerLink::create([
        'affiliate_professional_id' => $affiliateId,
        'brand_professional_id' => $brandId,
        'slot' => 1,
    ]);

    $rows = DB::table('notifications.notifications')
        ->where('category', 'brand_links')
        ->where('dedupe_key', "brand_link.created.{$affiliateId}.{$brandId}")
        ->get();

    // One row per professional, even with two creates.
    expect($rows)->toHaveCount(2);
});
