<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Menu;
use App\Models\Core\User\User;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/public/profiles/{handle}/menu
 *
 * Public, unauthenticated — consumed by the Astro sitepage to render a
 * business profile's food menu (categories + items scraped from UberEats /
 * DoorDash). Returns 404 when no fetched menu exists for the handle.
 */
class PublicMenuController extends ApiController
{
    public function show(string $handle): JsonResponse
    {
        $handleLc = strtolower(trim($handle));
        if ($handleLc === '') {
            return $this->error('Not found.', 404);
        }

        $userId = User::query()->where('handle_lc', $handleLc)->value('id');
        if (! $userId) {
            return $this->error('Not found.', 404);
        }

        // The owner can hide the menu from their sitepage via the Google
        // Business display toggles (display_settings.menu === false) — the
        // menu is GB-sourced, so the gate lives with that connection.
        $gbDisplay = IntegrationConnection::query()
            ->where('user_id', $userId)
            ->where('platform', 'google-business')
            ->where('is_active', true)
            ->first(['display_settings'])
            ?->display_settings;
        if ((($gbDisplay['menu'] ?? true) === false)) {
            return $this->error('Not found.', 404);
        }

        $menu = Menu::with([
            'categories' => fn ($q) => $q->with([
                'items' => fn ($q2) => $q2->orderBy('position'),
            ]),
        ])
            ->where('user_id', $userId)
            ->whereNotNull('last_fetched_at')
            ->first();

        if (! $menu) {
            return $this->error('Not found.', 404);
        }

        $currency = $menu->currency ?? 'AUD';

        $categories = $menu->categories
            ->map(fn ($cat) => [
                'name' => $cat->name,
                'items' => $cat->items->map(fn ($item) => [
                    'name' => $item->name,
                    'description' => $item->description,
                    'imageUrl' => $item->image_url,
                    // base_price stored in dollars as a float; format to 2dp.
                    'price' => $item->base_price !== null
                        ? number_format((float) $item->base_price, 2)
                        : null,
                    // DoorDash-sourced (null for Uber Eats): 👍 percent + count and
                    // the "#N Most liked" badges, rendered on the sitepage menu cards.
                    'rating' => $item->rating,
                    'ratingCount' => $item->rating_count,
                    'badges' => $item->badges,
                ])->values()->toArray(),
            ])
            ->filter(fn ($cat) => count($cat['items']) > 0)
            ->values()
            ->toArray();

        return $this->success([
            'data' => [
                'storeName' => $menu->store_name,
                'currency' => $currency,
                'categories' => $categories,
            ],
        ]);
    }
}
