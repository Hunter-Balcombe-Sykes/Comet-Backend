<?php

namespace App\Services\Design\Presets;

use App\Models\Core\Site\DesignKitContribution;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Platforms\Registry\Platform;
use Illuminate\Support\Collection;

/**
 * One assembled, typed view of everything we know about a site's owner, built
 * ONCE per resolve and handed to every EvidenceFactor. v1 factors each read a
 * single IntegrationConnection payload, so no factor could reason across
 * sources; this bag is the seam that makes cross-source factors (platform mix,
 * store price point, aesthetic expression) possible.
 *
 * Construction is cheap — it just holds references. The derived accessors
 * (platform slugs, store products, the Instagram payload) are memoised and only
 * do work when a factor actually asks. detect() implementations read from here;
 * they MUST NOT issue their own connection query (the resolver already loaded
 * the active connections, with shopBrands + their products eager-loaded).
 */
final class IdentityEvidence
{
    /** @var list<string>|null */
    private ?array $platformSlugs = null;

    /** @var list<array{price: float, currency: ?string}>|null */
    private ?array $storeProducts = null;

    private bool $instagramResolved = false;

    /** @var array<string, mixed>|null */
    private ?array $instagramPayload = null;

    /**
     * @param  Collection<int, IntegrationConnection>  $activeConnections  resolver-supplied, active, with shopBrands.products eager-loaded
     * @param  Collection<string, Collection<int, DesignKitContribution>>  $priorContributions  the site's existing contribution rows grouped by source (this resolve's "before" state); empty when unavailable
     */
    public function __construct(
        private readonly User $user,
        private readonly Site $site,
        private readonly Collection $activeConnections,
        private readonly Collection $priorContributions = new Collection,
    ) {}

    public function user(): User
    {
        return $this->user;
    }

    public function site(): Site
    {
        return $this->site;
    }

    /** @return Collection<int, IntegrationConnection> the already-loaded active connections */
    public function connections(): Collection
    {
        return $this->activeConnections;
    }

    /**
     * Distinct platform slugs the user has an active connection to. The raw
     * material for PlatformMixFactor's vibe classification.
     *
     * @return list<string>
     */
    public function platformSlugs(): array
    {
        return $this->platformSlugs ??= $this->activeConnections
            ->pluck('platform')
            ->filter(fn ($p): bool => is_string($p) && $p !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Every selected product across all active `shop` connections, normalised to
     * a numeric price + optional currency. Each store scraper (Shopify,
     * WooCommerce, BigCartel, Squarespace, generic JSON-LD) already writes a
     * DECIMAL price string on the product `data` blob (WooCommerce converts its
     * minor units at scrape time), so parsing to float here is uniform. Products
     * with no parseable positive price are dropped.
     *
     * Reads $connection->shopBrands (eager-loaded) and each brand's `products`
     * relation (ShopProduct rows, FOUND-25) — never a fresh query.
     *
     * @return list<array{price: float, currency: ?string}>
     */
    public function storeProducts(): array
    {
        if ($this->storeProducts !== null) {
            return $this->storeProducts;
        }

        $out = [];
        foreach ($this->activeConnections as $connection) {
            if ($connection->platform !== 'shop') {
                continue;
            }
            foreach ($connection->shopBrands as $brand) {
                $brandCurrency = is_string($brand->currency ?? null) ? $brand->currency : null;
                foreach ($brand->products as $product) {
                    $data = is_array($product->data ?? null) ? $product->data : [];
                    $price = $this->parsePrice($data['price'] ?? null);
                    if ($price === null) {
                        continue;
                    }
                    $currency = is_string($data['currency'] ?? null) && $data['currency'] !== ''
                        ? $data['currency']
                        : $brandCurrency;
                    $out[] = ['price' => $price, 'currency' => $currency];
                }
            }
        }

        return $this->storeProducts = $out;
    }

    /**
     * The raw Instagram connection payload (the first active Instagram
     * connection), or null. Kept as the raw array — AestheticExpressionFactor
     * reads bio/category/media signals that the trimmed InstagramPayload DTO
     * doesn't all surface.
     *
     * @return array<string, mixed>|null
     */
    public function instagramPayload(): ?array
    {
        if ($this->instagramResolved) {
            return $this->instagramPayload;
        }
        $this->instagramResolved = true;

        $connection = $this->activeConnections
            ->firstWhere('platform', Platform::Instagram->value);
        $payload = $connection?->payload;

        return $this->instagramPayload = is_array($payload) ? $payload : null;
    }

    /**
     * The value a given source last emitted for a target column, from this
     * resolve's "before" contribution snapshot — the anchor an auto factor uses
     * to decide whether a new conclusion is a real change worth committing
     * (hysteresis). Null when the source set nothing for that column last time.
     */
    public function priorContributionValue(string $source, string $targetVar): ?string
    {
        $rows = $this->priorContributions->get($source);
        if ($rows === null) {
            return null;
        }

        foreach ($rows as $row) {
            if ((string) $row->target_var === $targetVar) {
                return (string) $row->value;
            }
        }

        return null;
    }

    /**
     * Parse a scraped price string/number to a positive float, or null. Strips a
     * leading currency symbol and thousands separators so "$1,299.00" and
     * "1299.00" both parse; rejects zero / negative / non-numeric.
     */
    private function parsePrice(mixed $raw): ?float
    {
        if (is_int($raw) || is_float($raw)) {
            return $raw > 0 ? (float) $raw : null;
        }
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        // Keep digits, dot, minus; drop currency glyphs, spaces, thousands commas.
        $cleaned = preg_replace('/[^0-9.\-]/', '', $raw);
        if (! is_string($cleaned) || $cleaned === '' || ! is_numeric($cleaned)) {
            return null;
        }

        $value = (float) $cleaned;

        return $value > 0 ? $value : null;
    }
}
