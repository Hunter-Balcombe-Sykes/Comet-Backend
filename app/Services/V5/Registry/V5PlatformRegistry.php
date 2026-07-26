<?php

namespace App\Services\V5\Registry;

use App\Models\V5\ContentPool;
use App\Models\V5\ItemUrlTemplate;
use App\Models\V5\PlatformCategory;
use App\Models\V5\PlatformDefinition;
use App\Services\V5\Scraping\Normalization\PlatformUrlNormalizer;
use Illuminate\Support\Collection;

// V5 Platform Registry — single source of truth for all platform config.
// Reads from v5.platform_definitions + categories, resolves inheritance
// (platform → category → base), and provides URL matching for the router.
class V5PlatformRegistry
{
    private ?Collection $platforms = null;
    private ?Collection $categories = null;
    private ?Collection $itemUrlTemplates = null;

    public function __construct(
        private readonly PlatformUrlNormalizer $normalizer,
    ) {}

    // -------------------------------------------------------------------
    // Platform queries
    // -------------------------------------------------------------------

    /** All platforms with resolved config (inheritance applied). */
    public function all(): Collection
    {
        if ($this->platforms !== null) return $this->platforms;

        $categories = $this->categories();
        $base = config('v5.base');

        $this->platforms = PlatformDefinition::with(['categories', 'scrapeMethod', 'urlTemplates'])
            ->get()
            ->map(fn (PlatformDefinition $p) => $this->resolve($p, $categories, $base));

        return $this->platforms;
    }

    /** Platforms filtered by category name. */
    public function byCategory(string $categoryName): Collection
    {
        return $this->all()->filter(function (array $p) use ($categoryName) {
            return in_array($categoryName, $p['category_names'] ?? []);
        })->values();
    }

    /** Single platform by ID or slug. */
    public function find(string $idOrSlug): ?array
    {
        return $this->all()->first(fn (array $p) =>
            $p['id'] === $idOrSlug || ($p['slug'] ?? '') === $idOrSlug
        );
    }

    /** Platforms with is_source = true (after inheritance). */
    public function sourcePlatforms(): Collection
    {
        return $this->all()->filter(fn (array $p) => $p['is_source'] === true)->values();
    }

    // -------------------------------------------------------------------
    // URL matching (for router)
    // -------------------------------------------------------------------

    /** Match a URL against all platform URL format patterns. */
    public function matchUrl(string $url, ?string $categoryName = null): ?array
    {
        $platforms = $categoryName
            ? $this->byCategory($categoryName)
            : $this->all();

        foreach ($platforms as $platform) {
            $format = $platform['url_format'] ?? null;
            if (! $format) continue;

            // Convert template <handle> → regex capture group
            $pattern = $this->templateToRegex($format);
            if (preg_match($pattern, $url, $m)) {
                return [
                    'platform' => $platform,
                    'matched_value' => $m[1] ?? $url,
                    'match_type' => 'platform_url',
                ];
            }
        }

        return null;
    }

    /** Match a URL against item URL templates. */
    public function matchItemUrl(string $url): ?array
    {
        $templates = $this->itemUrlTemplates();

        foreach ($templates as $template) {
            $pattern = $this->templateToRegex($template->template);
            if (preg_match($pattern, $url, $m)) {
                $platform = $this->find($template->platform_definition_id);
                return [
                    'template' => $template,
                    'platform' => $platform,
                    'matched_value' => $m[1] ?? $url,
                    'match_type' => 'item_url',
                    'is_platform_syncable' => $template->is_platform_syncable,
                    'item_type' => $template->item_type,
                    'source_method' => $template->source_method ?? ($platform['source_method'] ?? 'api'),
                ];
            }
        }

        return null;
    }

    // -------------------------------------------------------------------
    // Categories
    // -------------------------------------------------------------------

    public function categories(): Collection
    {
        if ($this->categories !== null) return $this->categories;

        $this->categories = PlatformCategory::all()->keyBy('name');
        return $this->categories;
    }

    // -------------------------------------------------------------------
    // Inheritance resolution
    // -------------------------------------------------------------------

    /**
     * Resolve a platform's effective config through the inheritance chain:
     * Platform override → Category default → Base default.
     */
    private function resolve(PlatformDefinition $platform, Collection $categories, array $base): array
    {
        $categoryNames = $platform->categories->pluck('name')->toArray();
        $primaryCategory = $categoryNames[0] ?? 'other';

        // Merge category defaults for all categories this platform belongs to
        $categoryConfig = config("v5.categories.{$primaryCategory}", []);
        $categoryRules = $categoryConfig['rules'] ?? [];

        // Resolve each property through the chain
        $refreshInterval = $platform->refresh_interval
            ?? $categoryConfig['refresh_interval']
            ?? $base['refresh_interval'];

        $sourceMethod = $platform->source_method
            ?? $categoryConfig['source_method']
            ?? $base['source_method'];

        $isSource = $platform->is_source
            ?? $categories->get($primaryCategory)?->default_is_source
            ?? false;

        $isUrlSource = $platform->is_url_source
            ?? $categories->get($primaryCategory)?->default_is_url_source
            ?? false;

        $effectiveRules = $this->resolveRules(
            $platform,
            $categoryRules,
            $base['rules'] ?? []
        );

        // Check manual-only override
        $manualOnly = in_array($platform->slug ?? '', config('v5.manual_only_platforms', []));
        $autoSync = $manualOnly ? false : ($effectiveRules['auto_sync']['value'] ?? true);

        return [
            'id' => $platform->id,
            'name' => $platform->name,
            'slug' => $platform->slug ?? strtolower(str_replace(' ', '-', $platform->name)),
            'logo' => $platform->logo,
            'url' => $platform->url,
            'url_format' => $platform->url_format,
            'user_type' => $platform->user_type,
            'platform_colour' => $platform->platform_colour,
            'identifier_name_type' => $platform->identifier_name_type ?? 'handle',
            'category_names' => $categoryNames,
            'primary_category' => $primaryCategory,
            'is_source' => $isSource,
            'is_url_source' => $isUrlSource,
            'refresh_interval' => $autoSync ? $refreshInterval : null,
            'source_method' => $sourceMethod,
            'auto_sync' => $autoSync,
            'rules' => $effectiveRules,
            'scrape_method_id' => $platform->scrape_method_id,
            'scrape_method_name' => $platform->scrapeMethod?->name,
            'scrape_method_template' => $platform->scrapeMethod?->base_template,
            'platform_overrides' => $platform->scrapeMethod?->platform_overrides ?? [],
            'url_templates' => $platform->urlTemplates?->toArray() ?? [],
            'created_at' => $platform->created_at?->toIso8601String(),
        ];
    }

    private function resolveRules(
        PlatformDefinition $platform,
        array $categoryRules,
        array $baseRules
    ): array {
        $ruleNames = ['release_sync', 'full_sync', 'auto_sync'];
        $resolved = [];

        // Get platform-level rules from the source_rules table
        $platformRules = $platform->sourceRules ?? collect();

        foreach ($ruleNames as $ruleName) {
            $platformRule = $platformRules->firstWhere('rule_name', $ruleName);

            $default = $platformRule?->default_value
                ?? $categoryRules[$ruleName]['default']
                ?? $baseRules[$ruleName]['default']
                ?? false;

            $isEnabled = $platformRule?->is_enabled ?? true;
            $isApplicable = $platformRule?->is_applicable ?? true;

            $resolved[$ruleName] = [
                'value' => filter_var($default, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default,
                'is_enabled' => $isEnabled,
                'is_applicable' => $isApplicable,
                'inherited_from' => $platformRule ? 'platform' : (isset($categoryRules[$ruleName]) ? 'category' : 'base'),
            ];
        }

        return $resolved;
    }

    // -------------------------------------------------------------------
    // Content pools
    // -------------------------------------------------------------------

    public function contentPools(): Collection
    {
        return ContentPool::all();
    }

    /** Content pools that get their own standalone page (vs embedded in another page). */
    public function standalonePools(): Collection
    {
        return $this->contentPools()->filter(fn (ContentPool $pool) =>
            ! in_array($pool->name, ['reviews']) // reviews is embedded in workplace/business
        );
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    private function itemUrlTemplates(): Collection
    {
        if ($this->itemUrlTemplates !== null) return $this->itemUrlTemplates;
        $this->itemUrlTemplates = ItemUrlTemplate::all();
        return $this->itemUrlTemplates;
    }

    /**
     * Convert a URL format template like "https://platform.com/<handle>" into
     * a regex pattern that captures the handle/identifier.
     */
    private function templateToRegex(string $template): string
    {
        // FIRST replace placeholders with safe markers, THEN escape —
        // this avoids preg_quote mangling the < > brackets in placeholders.
        $replacementMap = [
            // Order matters: longer placeholders first to prevent partial matches
            '<accounthandle>' => '(?P<accounthandle>[\w.\-@]+)',
            '<itemidentifier>' => '(?P<itemidentifier>[\w.\-]+)',
            '<username>' => '(?P<username>[\w.]+)',
            '<channel>' => '(?P<channel>[\w\-]+)',
            '<handle>' => '(?P<handle>[\w.\-@]+)',
            '<slug>' => '(?P<slug>[\w\-]+)',
            '<id>' => '(?P<id>[\w\-]+)',
        ];

        // Markers use only characters preg_quote never touches (A-Z, 0-9, _)
        $markers = [];
        $marked  = $template;
        $idx = 0;
        foreach ($replacementMap as $placeholder => $regex) {
            $marker = '__PH' . $idx . '__';
            $markers[$marker] = $regex;
            $marked = str_replace($placeholder, $marker, $marked);
            $idx++;
        }

        $escaped = preg_quote($marked, '#');
        // Restore regex capture groups from markers
        $escaped = str_replace(array_keys($markers), array_values($markers), $escaped);

        return '#^'.$escaped.'#i';
    }
}
