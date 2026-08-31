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
 *   - `publicConfig` — platform-wide + site-level render knobs
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
     *     actions?: array{mode: string, entries: list<array<string, mixed>>},
     *     links?: list<array<string, mixed>>,
     *     pools?: array<string, array{items: list<array<string, mixed>>, latestItemId: string|null}>,
     *     brand?: array{logoFull: array<string, mixed>|null, logoSquare: array<string, mixed>|null},
     *     headshot?: array{url: string, urlHd: string|null, urlIcon: string|null}|null,
     *     services?: list<array<string, mixed>>,
     *     document?: array<string, mixed>|null,
     *     newsletter?: array<string, mixed>|null,
     *     contact?: array<string, mixed>|null,
     *     publicContact?: array{email: string|null, phone: string|null}|null,
     *     bio?: string|null,
     *     workplace?: array<string, mixed>|null,
     *     policies?: array<string, mixed>|null,
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
                // The partna professional's own photo (T17, 2026-08-27):
                // {url, urlHd, urlIcon} | null. The sitepage favicon prefers
                // urlIcon; the letter initial stays the fallback.
                'headshot' => $this->sections['headshot'] ?? null,
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

            // Whether a pre-account build is still running for this account —
            // null once claimed, or for an account that never had one. The
            // sitepage's isPreparing() reads it (F2, 2026-08-31).
            'buildState' => $this->sections['build_state'] ?? null,

            // Unified action list (2026-08-23) — always present, entries may be [].
            'actions' => $this->sections['actions'] ?? ['mode' => 'newest', 'entries' => []],

            // Per-user design kit. Partial — only contains stored (non-null)
            // columns from site.design_kits, mapped from flat snake_case DB
            // columns to nested camelCase groups. partna-pages merges this with
            // DESIGN_KIT_DEFAULTS (code-side) before passing to the skeleton.
            'designKit' => $designKitOut,

            // Which code-side architecture (page layout / how pages connect)
            // renders this site — 'staple' or 'scroll' (Site::ARCHITECTURE_IDS),
            // owner-picked on the dashboard's Site page; defaults to 'scroll'
            // (the platform default since 2026-08-27, migration 20260827120000).
            'architectureId' => $this->sections['architecture_id'] ?? Site::DEFAULT_ARCHITECTURE_ID,

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
