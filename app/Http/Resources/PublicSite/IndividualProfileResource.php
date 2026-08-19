<?php

namespace App\Http\Resources\PublicSite;

use App\Http\Resources\ApiResource;
use App\Models\Core\Site\Site;
use Illuminate\Http\Request;
use stdClass;

/**
 * Public-safe shape for an individual professional's profile page (§28.8).
 *
 * Consumed by the Astro Worker subrequest path (partna-pages). The payload
 * mirrors the skeleton-system contract (spec §3.4 + phase-8 engines):
 *   - `profile` — content (engine fields + base profile)
 *   - `designKit` — per-user design vars (nested camelCase), partial
 *   - `architectureId` — picks which code-side architecture renders
 *   - `publicConfig` — analytics endpoint + platform-wide keys
 *
 * partna-pages does the read-time merge of the partial `designKit` with
 * code-side defaults before passing to the skeleton.
 *
 * Each engine field falls back to a stable empty state:
 *   - object engines (document, newsletter) → null when nothing authored
 *   - list engines (links, services) → empty array
 *
 * Booking is a link-engine category, not a separate field — `bucketLinks`
 * in @partnaau/design-system splits the list at render time.
 *
 * INTENTIONAL EXCLUSIONS:
 *   - Legacy themeMode / accent / fontFamily / settings.design.* — removed in
 *     the skeleton-system cleanup. The full design surface is now design_kits.
 *   - `profile.gallery`, `profile.curatedGallery`, `designMedia`, `siteImages`
 *     — deleted outright by slice 7 unit E (owner ruling 2026-08-14). Curated
 *     imagery is the `media` pool; apps/pages reads break by design.
 *   - PII (primary_email, phone, auth_user_id, street address)
 *   - Anything brand- or commerce-related (the platform is individual-only).
 */
class IndividualProfileResource extends ApiResource
{
    /**
     * Single associative payload — keys mirror the output shape 1-to-1.
     * Missing keys degrade to sensible empties so the Resource never crashes
     * on a partial build.
     *
     * @param  array{
     *     site_id?: string|null,
     *     design_kit?: array<string, mixed>,
     *     architecture_id?: string|null,
     *     public_config?: array<string, mixed>,
     *     page_order?: list<string>,
     *     ranked_actions?: list<array<string, mixed>>,
     *     ordering?: array<string, mixed>,
     *     links?: list<array<string, mixed>>,
     *     pools?: array<string, array{items: list<array<string, mixed>>, latestItemId: string|null}>,
     *     brand?: array{logoFull: array<string, mixed>|null, logoSquare: array<string, mixed>|null},
     *     services?: list<array<string, mixed>>,
     *     document?: array<string, mixed>|null,
     *     newsletter?: array<string, mixed>|null,
     *     contact?: array<string, mixed>|null,
     *     publicContact?: array{email: string|null, phone: string|null}|null,
     *     workplace?: array<string, mixed>|null,
     * }  $sections
     */
    public function __construct(
        $resource,
        private readonly array $sections = [],
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        // Empty designKit must serialize as `{}` (object), not `[]` (array).
        // PHP's array → JSON encoder emits `[]` for any empty associative
        // array; cast to stdClass when there are no stored vars so the wire
        // payload matches the spec contract (designKit is always an object).
        $designKit = $this->sections['design_kit'] ?? [];
        $designKitOut = $designKit === [] ? new stdClass : $designKit;

        // Same empty-object coercion as $designKit above.
        $publicConfig = $this->sections['public_config'] ?? [];
        $publicConfigOut = $publicConfig === [] ? new stdClass : $publicConfig;

        // Engine fields preserve null-vs-array distinction precisely:
        //   - document/newsletter: null when no data is authored.
        //   - links/services (the pre-pool engine lists) LEFT the wire
        //     2026-08-19: `pools.custom_links` / `pools.services` are the
        //     public truth and the sitepage never read the old keys.
        return [
            // Content data — the profile itself + engine outputs. camelCase
            // keys for engine fields per spec §5 wire convention.
            'profile' => [
                'handle' => $this->handle,
                'displayName' => $this->display_name,
                'accountType' => $this->account_type?->value,
                'site_id' => $this->sections['site_id'] ?? null,

                // The content pools (platforms-as-sources, 2026-08-05):
                // {watch|listen|media: {items: [...], latestItemId}} — the
                // SELECTION each pool renders publicly, resolved LIVE (owner
                // chose no document cache: the site follows a pool edit
                // instantly). Items are render-ready — headline, url,
                // platform, creator, publishedAt, durationSeconds, thumbnail,
                // links[{platform,url,source}] for the per-item platform
                // buttons, origin. Always an object.
                'pools' => (object) ($this->sections['pools'] ?? []),
                // Brand logos (owner, 2026-08-17): {logoFull, logoSquare},
                // each {url, urlHd, urlSvg, urlIcon} | null. Null when the
                // owner never uploaded one — name-as-type is the fallback.
                'brand' => $this->sections['brand'] ?? ['logoFull' => null, 'logoSquare' => null],
                'document' => $this->sections['document'] ?? null,
                'newsletter' => $this->sections['newsletter'] ?? null,
                'contact' => $this->sections['contact'] ?? null,
                'publicContact' => $this->sections['publicContact'] ?? null,
                // Owner-authored About Me (users.bio, re-added 2026-08-19) —
                // string | null; the renderer owns the mount.
                'bio' => $this->sections['bio'] ?? null,
                'workplace' => $this->sections['workplace'] ?? null,
            ],

            // Taxonomy page order for the ONE architecture — presence + business
            // gated, popularity-ranked with canonical fallback. Top-level (a
            // render-time concern, not profile content). Always an array.
            'pageOrder' => $this->sections['page_order'] ?? [],

            // Unified ranked actions — ordered best-first, the lander renders the
            // top 6. Entries: {kind: page|item|button|custom, ref, label, url,
            // pageId, itemType, itemKey, score}. Already override-applied (when
            // the owner disabled smart actions this IS their manual list, customs
            // included) — consumers render, never re-derive. Always an array.
            'rankedActions' => $this->sections['ranked_actions'] ?? [],

            // Ordering preferences (defaults applied server-side): {smartPageOrder,
            // manualPageOrder, smartActions, manualActions}. pageOrder/rankedActions
            // above already reflect these — this object is for transparency +
            // dashboard/preview surfaces. Always an object.
            'ordering' => (object) ($this->sections['ordering'] ?? []),

            // Per-user design kit. Partial — only contains stored (non-null)
            // columns from site.design_kits, mapped from flat snake_case DB
            // columns to nested camelCase groups. partna-pages merges this with
            // DESIGN_KIT_DEFAULTS (code-side) before passing to the skeleton.
            'designKit' => $designKitOut,

            // Which code-side architecture (page layout / how pages connect)
            // renders this site — always 'staple' (single-architecture platform).
            'architectureId' => $this->sections['architecture_id'] ?? Site::DEFAULT_ARCHITECTURE_ID,
            // TRANSITION ALIAS — drop once apps/pages deploy reading architectureId is confirmed.

            // Platform-wide knobs the skeleton needs at render time (analytics
            // endpoint, etc.). Always an object.
            'publicConfig' => $publicConfigOut,

            // Resolved site policies — {privacy, terms}, each {mode, text,
            // sections}. Auto mode carries structured sections (the sitepage
            // renders them as disclosure panels at /privacy + /terms); manual
            // mode is the owner's own flat text. Null only on partial builds.
            'policies' => $this->sections['policies'] ?? null,
        ];
    }
}
