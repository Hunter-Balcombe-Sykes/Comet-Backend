<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Unified read-only brands index across all product platforms (Shopify +
// WooCommerce). Powers the dashboard's "Products" index card status endpoint
// and the unified Stores list in the products section. Each platform's own
// controller owns its write operations.
class ProductsController extends ApiController
{
    use ResolveCurrentUser;

    // GET /api/platforms/products/brands — all connected product store brands
    // across Shopify and WooCommerce, each tagged with `platform`.
    public function brands(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $brands = collect(['shopify', 'woocommerce'])
            ->flatMap(function (string $platform) use ($user) {
                $connection = $user->integrationConnections()
                    ->where('platform', $platform)
                    ->first();

                if (! $connection || ! is_array($connection->payload)) {
                    return [];
                }

                return collect($connection->payload)
                    ->filter(fn ($brand) => is_array($brand) && isset($brand['url']))
                    ->map(fn ($brand) => array_merge($brand, ['platform' => $platform]))
                    ->values()
                    ->all();
            })
            ->values()
            ->all();

        return $this->success(['brands' => $brands]);
    }
}
