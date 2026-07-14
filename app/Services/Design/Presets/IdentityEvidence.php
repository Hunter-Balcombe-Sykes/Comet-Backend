<?php

namespace App\Services\Design\Presets;

use App\Models\Core\Site\DesignKitContribution;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\User;
use App\Services\Platforms\Registry\Platform;
use App\Services\Platforms\Registry\PlatformCategory;
use App\Services\Platforms\Registry\PlatformRegistry;
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

    private bool $businessHoursResolved = false;

    /** @var array<string, list<array{open:string, close:string}>>|null */
    private ?array $businessHours = null;

    private bool $mediaPaletteResolved = false;

    /** @var array<string, mixed>|null */
    private ?array $mediaPalette = null;

    private bool $musicGenreResolved = false;

    private ?string $musicGenre = null;

    private bool $cuisineHintResolved = false;

    private ?string $cuisineHint = null;

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
     * connection), or null. Kept as the raw array rather than the typed
     * InstagramPayload DTO so a caller can read any stored key without a DTO
     * round-trip. Today's readers (AestheticExpressionFactor, RecipeSignals) only
     * consult businessCategory/fullName; BE2 (2026-07) added genuine bio-derived
     * data to this payload — website + bioLinks[] — for a future aesthetic signal
     * to read, not yet wired into a Factor.
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
     * The site's structured weekly opening hours, or null when none are stored /
     * unparseable. Read from site.workplaces.opening_hours (a per-day jsonb the
     * Google-Business precedence sync fills, or the user sets manually):
     *   {"mon":[{"open":"0900","close":"1700"}], ..., "exceptions":[]}
     *
     * Normalised to a day => list-of-{open,close} map, keeping ONLY the seven
     * weekday keys with at least one well-formed HHMM open/close pair (the
     * "exceptions" bucket and any malformed period are dropped). Returns null
     * rather than an empty map when nothing usable exists, so HoursRhythmFactor's
     * "no parseable structure" abstain is a simple null check.
     *
     * Uses a keyed Workplace query (NOT $site->workplace) — mirrors
     * PreviousWebsiteFactor, so it never trips Model::preventLazyLoading and
     * needs no eager-load wiring in the resolver.
     *
     * @return array<string, list<array{open:string, close:string}>>|null
     */
    public function businessHours(): ?array
    {
        if ($this->businessHoursResolved) {
            return $this->businessHours;
        }
        $this->businessHoursResolved = true;

        $workplace = Workplace::query()->find((string) $this->site->id);
        $raw = $workplace?->opening_hours;
        if (! is_array($raw)) {
            return $this->businessHours = null;
        }

        $days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        $out = [];
        foreach ($days as $day) {
            $periods = $raw[$day] ?? null;
            if (! is_array($periods)) {
                continue;
            }
            $clean = [];
            foreach ($periods as $period) {
                if (! is_array($period)) {
                    continue;
                }
                $open = $this->normaliseClock($period['open'] ?? null);
                $close = $this->normaliseClock($period['close'] ?? null);
                if ($open !== null && $close !== null) {
                    $clean[] = ['open' => $open, 'close' => $close];
                }
            }
            if ($clean !== []) {
                $out[$day] = $clean;
            }
        }

        return $this->businessHours = $out === [] ? null : $out;
    }

    /**
     * Dominant-colour / palette metadata for the user's gallery imagery,
     * aggregated to a single representative read the ImageryPaletteFactor
     * consumes: {saturation: float 0..1, warm: bool} (#76 Part A).
     *
     * Source: the `palette` jsonb ImageVariantService writes per processed image
     * ({dominant, colors[], saturation, warm}). We read the site's gallery +
     * content pool image rows that HAVE a palette and:
     *   • average their `saturation` (the vivid/muted axis is a gallery-wide feel),
     *   • take a simple majority vote on `warm` (ties / mostly-neutral → not warm),
     * mirroring the per-image warm rule so a lone warm shot can't flip a cool set.
     *
     * Returns [] when no gallery image has a stored palette yet (grandfathered or
     * pre-backfill), so ImageryPaletteFactor keeps abstaining cleanly. Uses a
     * keyed query (NOT a lazy relation) so it never trips Model::preventLazyLoading
     * and needs no resolver eager-load wiring — same tactic as businessHours(). The
     * query is wrapped so ANY read failure degrades to the abstain path — a colour
     * read must never break preset resolution (additive, low-blast-radius: #76).
     *
     * @return array{saturation?: float, warm?: bool} [] when no palette source exists
     */
    public function mediaPalette(): array
    {
        if ($this->mediaPaletteResolved) {
            return $this->mediaPalette ?? [];
        }
        $this->mediaPaletteResolved = true;

        try {
            $rows = SiteMedia::query()
                ->where('site_id', (string) $this->site->id)
                ->whereIn('pool', SiteMedia::GALLERY_POOLS)
                ->where('media_type', SiteMedia::MEDIA_TYPE_IMAGE)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->whereNotNull('palette')
                ->pluck('palette');
        } catch (\Throwable) {
            return $this->mediaPalette = []; // read failed — abstain, never break resolve
        }

        $satSum = 0.0;
        $satCount = 0;
        $warmVotes = 0;
        $coolVotes = 0;

        foreach ($rows as $raw) {
            // `palette` is array-cast on the model, but a raw pluck may hand back
            // the JSON string — normalise both.
            $palette = is_array($raw) ? $raw : (is_string($raw) ? json_decode($raw, true) : null);
            if (! is_array($palette)) {
                continue;
            }

            $sat = $palette['saturation'] ?? null;
            if (is_int($sat) || is_float($sat)) {
                $satSum += (float) $sat;
                $satCount++;
            }

            if (array_key_exists('warm', $palette)) {
                $palette['warm'] ? $warmVotes++ : $coolVotes++;
            }
        }

        if ($satCount === 0 && $warmVotes === 0 && $coolVotes === 0) {
            return $this->mediaPalette = []; // no usable palette metadata — abstain
        }

        $out = [];
        if ($satCount > 0) {
            $out['saturation'] = round($satSum / $satCount, 4);
        }
        if ($warmVotes > 0 || $coolVotes > 0) {
            $out['warm'] = $warmVotes > $coolVotes;
        }

        return $this->mediaPalette = $out;
    }

    /**
     * A recognised music genre from the first active music connection that
     * carries one, lower-cased, or null.
     *
     * The music platforms (Spotify, Apple Music, SoundCloud, Bandcamp, …)
     * store an oEmbed-shaped EmbedPayload ({url, name, thumbnail, embedUrl, link,
     * artistId}) that carries NO genre today, so in practice this returns null and
     * MusicGenreFactor abstains. It still probes a small set of tolerated genre
     * keys so that if a future payload enrichment adds one, the factor lights up
     * with no further wiring. Never fabricates a genre.
     */
    public function musicGenre(): ?string
    {
        if ($this->musicGenreResolved) {
            return $this->musicGenre;
        }
        $this->musicGenreResolved = true;

        $registry = app(PlatformRegistry::class);
        foreach ($this->activeConnections as $connection) {
            $slug = is_string($connection->platform ?? null) ? $connection->platform : '';
            if ($slug === '' || $registry->get($slug)?->getCategory() !== PlatformCategory::Music) {
                continue;
            }
            $payload = $connection->payload;
            if (! is_array($payload)) {
                continue;
            }
            // Tolerated genre keys — none are written today; forward-compatible probe.
            foreach (['genre', 'primaryGenre', 'genreName'] as $key) {
                $value = $payload[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    return $this->musicGenre = strtolower(trim($value));
                }
                // Some feeds carry a list; take the first non-empty string.
                if (is_array($value)) {
                    foreach ($value as $item) {
                        if (is_string($item) && trim($item) !== '') {
                            return $this->musicGenre = strtolower(trim($item));
                        }
                    }
                }
            }
        }

        return $this->musicGenre = null;
    }

    /**
     * A coarse cuisine hint for a food business, derived from the Google-Business
     * connection's declared category (Places primaryTypeDisplayName, e.g. "Italian
     * restaurant", "Coffee shop"), or null when there is no food-business signal.
     *
     * Returns one of a small closed set of hint tokens (fine_dining, cafe,
     * fast_casual, or a specific-cuisine token like 'italian') that CuisineFactor
     * and LaunchRecipeFactor map to a look. Non-food categories and unrecognised
     * strings yield null. This is the only cuisine signal stored today — there is
     * no richer serves-cuisine field on the payload.
     */
    public function cuisineHint(): ?string
    {
        if ($this->cuisineHintResolved) {
            return $this->cuisineHint;
        }
        $this->cuisineHintResolved = true;

        $connection = $this->activeConnections
            ->firstWhere('platform', Platform::GoogleBusiness->value);
        $category = $connection && is_array($connection->payload)
            ? ($connection->payload['category'] ?? null)
            : null;

        return $this->cuisineHint = is_string($category)
            ? CuisineLexicon::hintFor($category)
            : null;
    }

    /**
     * Normalise a clock value to a 4-digit "HHMM" string, or null. Accepts the
     * stored "0900" form and tolerates "9:00" / "09:00" / integer 900. Rejects
     * anything that isn't a valid 00:00–23:59 time.
     */
    private function normaliseClock(mixed $raw): ?string
    {
        if (is_int($raw)) {
            $raw = str_pad((string) $raw, 4, '0', STR_PAD_LEFT);
        }
        if (! is_string($raw)) {
            return null;
        }

        $digits = preg_replace('/[^0-9]/', '', $raw);
        if (! is_string($digits) || $digits === '') {
            return null;
        }
        $digits = str_pad($digits, 4, '0', STR_PAD_LEFT);
        if (strlen($digits) !== 4) {
            return null;
        }

        $hour = (int) substr($digits, 0, 2);
        $minute = (int) substr($digits, 2, 2);
        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return $digits;
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
