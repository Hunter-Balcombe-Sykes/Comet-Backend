<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Services\SmartLinks\SafeUrlFetcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

// Test-mode endpoints for the Stan.store integration. Mirrors the other
// platform tests: single-tenant cache, no auth, no migration. Takes a Stan
// username, hits the two unauthenticated Stan APIs, and stores the creator's
// email + published products. Full spec + field mapping:
//   ~/Developer/platform link capabilites/stan-store.md
//
// Stan product images are on a no-expiry CDN (assets.stanwith.me), so unlike
// Instagram we store the URLs directly — no R2 mirroring needed.
class StanController extends ApiController
{
    private const SELECTION_KEY = 'platforms.stan.selection';

    private const CACHE_TTL_DAYS = 30;

    private const USERS_API = 'https://api.stanwith.me/api/v1/users/';

    private const STORES_API = 'https://api.stanwith.me/api/v1/stores?username=';

    private const SCRAPE_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    // POST /api/platforms/stan/connect — fetch a Stan store, store + return.
    public function connect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:120'],
        ]);

        $username = $this->normalizeUsername($validated['username']);
        if ($username === '') {
            return $this->error('Enter your Stan store username.', 422);
        }

        $selection = $this->fetchStan($username);
        if ($selection === null) {
            return $this->error('Could not find that Stan store — check the username.', 404);
        }

        Cache::put(self::SELECTION_KEY, $selection, now()->addDays(self::CACHE_TTL_DAYS));

        return $this->success($selection);
    }

    // GET /api/platforms/stan/selection — read the saved blob (partna-pages
    // reads this; the dashboard restores its state from it).
    public function selection(): JsonResponse
    {
        return $this->success(['selection' => Cache::get(self::SELECTION_KEY)]);
    }

    // DELETE /api/platforms/stan — clear the saved selection.
    public function forget(): JsonResponse
    {
        Cache::forget(self::SELECTION_KEY);

        return $this->success(['selection' => null]);
    }

    // ── internals ────────────────────────────────────────────────

    // Reduce a pasted stan.store URL to the username path segment; otherwise
    // strip a leading @. Case-sensitive — Stan usernames are case-preserving.
    private function normalizeUsername(string $input): string
    {
        $s = trim($input);
        if (preg_match('~stan\.store/([^/?#]+)~i', $s, $m)) {
            return $m[1];
        }

        return ltrim($s, '@');
    }

    /**
     * Fetch the two Stan APIs and shape per the spec. Returns null when the
     * username isn't found (409); aborts 502 on other upstream failures.
     *
     * @return array{username:string, email:?string, products:list<array<string,mixed>>}|null
     */
    private function fetchStan(string $username): ?array
    {
        $headers = ['User-Agent' => self::SCRAPE_USER_AGENT];
        $userRes = $this->fetcher->fetch(self::USERS_API.rawurlencode($username), $headers);
        $storeRes = $this->fetcher->fetch(self::STORES_API.rawurlencode($username), $headers);

        if ($userRes['status'] === 409 || $storeRes['status'] === 409) {
            return null;
        }
        if ($userRes['status'] !== 200 || $storeRes['status'] !== 200) {
            abort(502, "Stan API returned HTTP {$userRes['status']} / {$storeRes['status']}");
        }

        $user = json_decode($userRes['body'], true);
        $store = json_decode($storeRes['body'], true);
        if (! is_array($user) || ! is_array($store)) {
            abort(502, 'Stan API returned non-JSON.');
        }

        $currency = data_get($user, 'user.data.currency');
        $email = data_get($user, 'user.data.socials.mail_to');

        $pages = data_get($store, 'store.pages', []);
        if (! is_array($pages)) {
            $pages = [];
        }

        $products = collect($pages)
            // Keep published products with a real slug (excludes external
            // link-type products, which always have slug=null) — spec §Filter.
            ->filter(fn ($p) => data_get($p, 'slug') !== null && (int) data_get($p, 'status') === 2)
            ->sortBy(fn ($p) => (int) data_get($p, 'order', 0))
            ->map(function ($p) use ($username, $currency) {
                $amount = (float) (data_get($p, 'data.product.price.amount') ?? 0);
                $isPaid = $amount > 0;

                $product = [
                    'deepLink' => "https://stan.store/{$username}/p/".data_get($p, 'slug'),
                    'title' => data_get($p, 'data.button.title') ?: data_get($p, 'data.product.title') ?: '',
                    'image' => data_get($p, 'data.button.image'),
                    'buttonText' => data_get($p, 'data.button.button_text'),
                    'description' => data_get($p, 'data.product.description'),
                    'isPaid' => $isPaid,
                    'price' => $isPaid ? data_get($p, 'data.product.price.amount') : null,
                    'currency' => $isPaid ? $currency : null,
                    // community → recurring; everything else one-off (spec §Duration).
                    'duration' => $isPaid ? (data_get($p, 'type') === 'community' ? 'monthly' : 'one-time') : null,
                ];

                return $product;
            })
            ->values()
            ->all();

        return [
            'username' => $username,
            'email' => $email,
            'products' => $products,
        ];
    }
}
