<?php

namespace App\Services\Shop;

use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A store tile whose TITLE carries a code ("Gamma+ - CODE: TEEGAN10") gives
 * that store its discount code, fill-if-empty — an owner-set code is never
 * overwritten. Matched by the store's host so a tile url with a path still
 * finds the storefront.
 *
 * Two callers (2026-09-02): the link-page importer, for a tile the router
 * placed as a store, and CommerceProbeJob, for a tile the router did NOT
 * know (gammaplus.com.au) — that storefront is minted by the probe, on the
 * queue, after the import has finished, so the importer's own attempt found
 * no row (teegandyson, measured 2026-09-02). The probe carries the code and
 * adopts it once the store exists.
 */
final class DiscountCodeAdopter
{
    /** @return int rows filled */
    public function adopt(User $user, string $url, string $code): int
    {
        $host = strtolower((string) preg_replace('~^www\.~', '', (string) parse_url($url, PHP_URL_HOST)));
        if ($host === '' || trim($code) === '') {
            return 0;
        }
        try {
            $n = DB::connection('pgsql')->table('content.storefronts')
                ->where('user_id', $user->id)
                ->where(fn ($q) => $q->whereNull('discount_code')->orWhere('discount_code', ''))
                ->where(fn ($q) => $q->where('url', 'like', '%'.$host.'%')->orWhere('source_url', 'like', '%'.$host.'%'))
                ->update(['discount_code' => trim($code), 'updated_at' => now()]);
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
        if ($n > 0) {
            Log::info('shop.discount_code_adopted', ['user_id' => (string) $user->id, 'host' => $host, 'code' => $code, 'rows' => $n]);
        }

        return $n;
    }
}
