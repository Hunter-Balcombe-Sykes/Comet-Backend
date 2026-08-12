<?php

namespace App\Services\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\ShopBrand;
use App\Models\Core\Site\ShopProduct;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Shop\ShopContentWriter;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

// Job-context seeding of ONE individually-added product from a scanned product
// page (signup-v2 C2). Mirrors ShopController::addProduct()'s locked body —
// the reserved 'individual' bucket, MAX_INDIVIDUAL_PRODUCTS cap, dedup by
// productId, newest-first transactional rebuild — without its HTTP/policy
// wrapper (same convention as EventsSeeder/ShopBrandSeeder; see their
// docblocks for the server-derived-user + caller-gating contract).
class ShopProductSeeder
{
    /** Mirrors ShopController::INDIVIDUAL_BRAND_ID — keep in lockstep. */
    private const INDIVIDUAL_BRAND_ID = 'individual';

    /** Mirrors ShopController::MAX_INDIVIDUAL_PRODUCTS — keep in lockstep. */
    private const MAX_INDIVIDUAL_PRODUCTS = 20;

    /** Mirrors ShopController::MARKER (FOUND-25 relational storage anchor). */
    private const MARKER = ['storage' => 'relational'];

    public function __construct(
        private readonly IntegrationConnectionCacheRefresher $refresher,
        private readonly ShopContentWriter $content,
    ) {}

    /**
     * @param  array<string,mixed>  $product  GenericShopScraper::readProductPage()'s product shape
     */
    public function seed(User $user, array $product): bool
    {
        // Tombstone parity with ShopBrandSeeder: an explicitly-removed shop
        // connection is never resurrected by a scan.
        $wasDisconnected = IntegrationConnection::onlyTrashed()
            ->where('user_id', $user->id)->where('platform', 'shop')
            ->exists();
        $hasLive = IntegrationConnection::query()
            ->where('user_id', $user->id)->where('platform', 'shop')
            ->exists();
        if ($wasDisconnected && ! $hasLive) {
            return false;
        }

        $key = CacheKeyGenerator::platformConnectionLock('shop', (string) $user->id);
        try {
            $written = Cache::lock($key, 10)->block(5, function () use ($user, $product): bool {
                $connection = IntegrationConnection::updateOrCreate(
                    ['user_id' => $user->id, 'platform' => 'shop', 'resource_id' => 'shop'],
                    [
                        'payload' => self::MARKER,
                        'is_active' => true,
                        'last_refreshed_at' => now(),
                        'last_refresh_status' => 'ok',
                        'last_refresh_error' => null,
                        'consecutive_failures' => 0,
                    ],
                );

                $maxPosition = ShopBrand::where('connection_id', $connection->id)->max('position');
                $individual = ShopBrand::firstOrCreate(
                    ['connection_id' => $connection->id, 'brand_id' => self::INDIVIDUAL_BRAND_ID],
                    [
                        'provider' => ShopProviderDetector::PROVIDER_GENERIC,
                        'url' => '',
                        'source_url' => '',
                        'currency' => $product['currency'] ?? null,
                        'discount_code' => '',
                        'is_individual' => true,
                        'position' => ($maxPosition === null ? -1 : $maxPosition) + 1,
                    ],
                );

                $productId = $product['productId'] ?? null;

                // Newest first, de-duped by productId, capped — byte-for-byte
                // the addProduct() ordering contract. Reads site.shop_products
                // unchanged (Task 6 brief §Step 4: "build $ordered as today")
                // — ShopController::addProduct() (the human path, not
                // repointed by this task) is still the thing keeping this
                // table current for the individual bucket. KNOWN GAP: once
                // this seeder is the ONLY writer to touch a given product
                // (i.e. two seed() calls for the same user with no
                // addProduct() in between — CommerceProbeJob can legitimately
                // fire this more than once per user as different scanned
                // links resolve), the second call's $ordered won't see what
                // the first call wrote to content.* (this table stays frozen
                // for that item), and syncStore()'s retire-absent will drop
                // it. Flagged, not fixed here — see the task report.
                $ordered = ShopProduct::where('brand_id', $individual->id)
                    ->orderBy('position')
                    ->get()
                    ->reject(fn (ShopProduct $p) => $p->product_id === $productId)
                    ->map(fn (ShopProduct $p) => $p->data)
                    ->prepend($product)
                    ->take(self::MAX_INDIVIDUAL_PRODUCTS)
                    ->values();

                // 5a §3.5 / Task 6: content.* is the reconciled destination —
                // the legacy delete+reinsert above this comment used to also
                // rebuild site.shop_products; that write stops here.
                $collectionId = $this->content->upsertStore($individual, (string) $user->id);
                $this->content->syncStore((string) $user->id, $collectionId, $ordered->all(), $individual->currency);

                $this->refresher->refresh($connection);

                return true;
            });
        } catch (LockTimeoutException) {
            Log::warning('shop_product_seeder.lock_timeout', ['user_id' => (string) $user->id]);

            return false;
        }

        return $written;
    }
}
