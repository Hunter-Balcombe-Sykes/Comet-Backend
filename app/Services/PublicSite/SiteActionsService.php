<?php

namespace App\Services\PublicSite;

use App\Enums\SitepageId;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\PublicSite\Actions\ActionVocabulary;
use Illuminate\Support\Collection;

/**
 * Unified site ACTIONS — the one ranked list the lander renders its CTA
 * buttons from. Fixed vocabulary (App\Services\PublicSite\Actions\
 * ActionVocabulary): 24 static ids + 2 dynamic families (ordering:<id>,
 * custom:<key>). Every action is {id, kind: page|external, label, pageId,
 * url, platform, sourceCreatedAt} — `id` is both the catalog identity and the
 * storage key (content_key in analytics.content_popularity_scores).
 *
 * This service owns the POOL (what actions exist right now, presence-gated
 * per site) and the wire resolution (ordering the pool by stored action
 * ranks, or by the owner's manual list when smart_actions is off). The
 * SCORING lives in RankedActionsComputer and runs inside
 * analytics:compute-popularity — this class never scores anything.
 *
 * Consumed by IndividualProfilePayloadBuilder (public payload `rankedActions`
 * + `ordering`), UserSiteActionsController (dashboard picker data) and
 * ComputeContentPopularityScores (pool for the scoring run).
 *
 * Design spec: docs/superpowers/plans/2026-07-23-actions-rebuild-demand-rate.md
 */
class SiteActionsService
{
    /**
     * The 17 media + social action ids resolved identically: prefer an
     * active IntegrationConnection for the platform; else fall back to a
     * live platform-tagged link block (D4). Order = ActionVocabulary
     * canonical order, kept explicit here (not sliced) so a future
     * vocabulary reorder can't silently reshuffle this list's meaning.
     *
     * @var list<string>
     */
    private const PLATFORM_ACTION_IDS = [
        'spotify', 'soundcloud', 'apple-music', 'apple-podcasts', 'twitch',
        'instagram', 'facebook', 'linkedin', 'youtube', 'tiktok', 'x',
        'snapchat', 'pinterest', 'threads', 'discord', 'reddit', 'telegram',
    ];

    /**
     * Action id → underlying platform slug, for the one id that diverges
     * from its slug (config/partna.php's social_platforms + the refresh-
     * interval registry both key Apple Podcasts singular: 'apple-podcast').
     * Every other id in PLATFORM_ACTION_IDS equals its own platform slug.
     *
     * @var array<string, string>
     */
    private const ACTION_ID_TO_PLATFORM_SLUG = [
        'apple-podcasts' => 'apple-podcast',
    ];

    // Defensive cap on a CUSTOM action label at emit time (mirrors the request
    // validator's max:80). Bounds a stray/unvalidated write, not a normal one.
    private const CUSTOM_LABEL_MAX = 80;

    public function __construct(
        private readonly SitepageDataResolverService $resolver,
    ) {}

    /**
     * Enumerate the site's live action pool — every id from the fixed
     * vocabulary the site currently has content for. Sections/booking are
     * injectable so callers that already loaded them (payload builder, the
     * scoring command) don't re-query; when omitted they're loaded here.
     *
     * Pool entry shape:
     *   {id: string, kind: 'page'|'external', label: string,
     *    pageId: ?string,      // page kind only — the taxonomy page-id
     *    url: ?string,         // external kind only — already safeHref-gated
     *    platform: ?string,    // media/social ids only — the icon slug
     *    sourceCreatedAt: ?string}  // ISO timestamp of the owning connection/block, when known
     *
     * @param  Collection<string, Block>|null  $sections
     * @param  array{state: string, data: array|null}|null  $booking
     * @return list<array<string, mixed>>
     */
    public function pool(User $pro, ?Site $site, ?Collection $sections = null, ?array $booking = null): array
    {
        if ($site === null) {
            return [];
        }

        $sections ??= $this->resolver->loadSections($site);
        $booking ??= $this->resolver->getBooking($site, $sections);

        $caps = AccountCapabilities::for($pro);
        $present = array_flip($this->resolver->presentPageIds($site, $caps, $sections));
        $links = $this->resolver->getLinks($site, $booking);
        $linkCreatedAt = $this->linkBlockCreatedAt($site);

        $connectionsByPlatform = [];
        foreach (
            IntegrationConnection::query()
                ->where('user_id', $pro->id)
                ->where('is_active', true)
                ->get(['id', 'user_id', 'platform', 'resource_id', 'payload', 'created_at']) as $conn
        ) {
            $connectionsByPlatform[strtolower((string) $conn->platform)][] = $conn;
        }

        $out = [];

        $reservation = $this->earliestConnection($connectionsByPlatform, ['opentable', 'resdiary', 'nowbookit']);
        if ($reservation !== null) {
            $url = $this->safeHref($this->connectionPayload($reservation)['url'] ?? null);
            if ($url !== null) {
                $out[] = $this->entry('reservations', 'external', 'Reservations', url: $url, createdAt: $reservation->created_at);
            }
        }

        if (isset($present['shop'])) {
            $out[] = $this->entry('shop', 'page', ActionVocabulary::labelFor('shop'), pageId: 'shop');
        }

        $bandcamp = $this->earliestConnection($connectionsByPlatform, ['bandcamp']);
        if ($bandcamp !== null) {
            $out[] = $this->entry('shop-tracks', 'page', ActionVocabulary::labelFor('shop-tracks'), pageId: 'shop-tracks', createdAt: $bandcamp->created_at);
        }

        if (isset($present['events'])) {
            $out[] = $this->entry('events', 'page', ActionVocabulary::labelFor('events'), pageId: 'events');
        }

        // D1: services page present → page action to /services. Else, when a
        // booking link/provider is live, external action straight to that URL.
        // Never both — an owner with both sees the richer /services page.
        if (isset($present['services'])) {
            $out[] = $this->entry('booking-services', 'page', ActionVocabulary::labelFor('booking-services'), pageId: 'services');
        } elseif (($booking['state'] ?? null) === 'live') {
            $url = $this->safeHref($booking['data']['resolved_url'] ?? null);
            if ($url !== null) {
                $out[] = $this->entry('booking-services', 'external', ActionVocabulary::labelFor('booking-services'), url: $url);
            }
        } elseif (($url = $this->incompleteBookingUrl($connectionsByPlatform)) !== null) {
            $out[] = $this->entry('booking-services', 'external', ActionVocabulary::labelFor('booking-services'), url: $url);
        }

        if (isset($present['menu'])) {
            $out[] = $this->entry('menu', 'page', ActionVocabulary::labelFor('menu'), pageId: 'menu');
        }

        if (isset($present['contact'])) {
            $out[] = $this->entry('contact', 'page', ActionVocabulary::labelFor('contact'), pageId: 'contact');
        }

        foreach (self::PLATFORM_ACTION_IDS as $actionId) {
            $slug = self::ACTION_ID_TO_PLATFORM_SLUG[$actionId] ?? $actionId;
            $conn = $this->earliestConnection($connectionsByPlatform, [$slug]);
            $url = $this->platformConnectionUrl($conn, $slug);
            $createdAt = $conn?->created_at;

            if ($url === null) {
                // D4 fallback: a live link block tagged with this platform.
                $block = collect($links)->first(fn (array $l): bool => ($l['platform'] ?? null) === $slug);
                if ($block !== null) {
                    $url = $this->safeHref($block['url'] ?? null);
                    $createdAt = $linkCreatedAt[$block['id']] ?? null;
                }
            }

            if ($url !== null) {
                $out[] = $this->entry($actionId, 'external', ActionVocabulary::labelFor($actionId), url: $url, platform: $actionId, createdAt: $createdAt);
            }
        }

        if ($caps->can_use_online_ordering) {
            foreach ($connectionsByPlatform['online-ordering'] ?? [] as $conn) {
                $payload = $this->connectionPayload($conn);
                $url = $this->safeHref($payload['url'] ?? null);
                if ($url === null) {
                    continue;
                }
                $label = trim((string) ($payload['name'] ?? ''));
                $out[] = $this->entry(
                    'ordering:'.$conn->resource_id,
                    'external',
                    $label !== '' ? $label : 'Order online',
                    url: $url,
                    createdAt: $conn->created_at,
                );
            }
        }

        // D2: custom links — every live link block that ISN'T the synthesized
        // booking row and isn't one of the 17 platform ids above, PLUS every
        // active 'custom' platform connection. Deduped by URL; a block wins
        // over a connection at the same URL (first-write-wins via `??=`).
        $platformSlugsInVocab = array_map(
            static fn (string $id): string => self::ACTION_ID_TO_PLATFORM_SLUG[$id] ?? $id,
            self::PLATFORM_ACTION_IDS,
        );
        $byUrl = [];
        foreach ($links as $link) {
            if ((string) $link['id'] === '' || ($link['category'] ?? null) === 'booking') {
                continue; // synthesized booking row — handled by booking-services
            }
            $platform = $link['platform'] ?? null;
            if ($platform !== null && in_array($platform, $platformSlugsInVocab, true)) {
                continue; // already emitted above as a platform action
            }
            $url = $this->safeHref($link['url'] ?? null);
            if ($url === null) {
                continue;
            }
            $title = trim((string) ($link['title'] ?? ''));
            $byUrl[$url] ??= [
                'key' => (string) $link['id'],
                'label' => $title !== '' ? $title : $url,
                'createdAt' => $linkCreatedAt[(string) $link['id']] ?? null,
            ];
        }
        foreach ($connectionsByPlatform['custom'] ?? [] as $conn) {
            $payload = $this->connectionPayload($conn);
            $url = $this->safeHref($payload['url'] ?? null);
            if ($url === null) {
                continue;
            }
            $name = trim((string) ($payload['name'] ?? ''));
            $byUrl[$url] ??= [
                'key' => (string) $conn->resource_id,
                'label' => $name !== '' ? $name : $url,
                'createdAt' => $conn->created_at,
            ];
        }
        foreach ($byUrl as $url => $c) {
            $out[] = $this->entry('custom:'.$c['key'], 'external', (string) $c['label'], url: $url, createdAt: $c['createdAt']);
        }

        return $out;
    }

    /**
     * The connection's own profile/channel URL for a media/social platform.
     * Instagram + YouTube store only a handle (no direct url/link field in
     * the public allowlist) — rebuild their canonical URL the same way
     * apps/pages/src/content/platforms/social-links.ts does, so the action's
     * href always matches what the sitepage itself would show. Every other
     * platform exposes `url` or `link` directly.
     */
    private function platformConnectionUrl(?IntegrationConnection $conn, string $slug): ?string
    {
        if ($conn === null) {
            return null;
        }
        $payload = $this->connectionPayload($conn);

        return match ($slug) {
            'instagram' => isset($payload['username']) && trim((string) $payload['username']) !== ''
                ? $this->safeHref('https://www.instagram.com/'.trim((string) $payload['username']))
                : null,
            'youtube' => isset($payload['handle']) && trim((string) $payload['handle']) !== ''
                ? $this->safeHref('https://www.youtube.com/@'.trim((string) $payload['handle']))
                : null,
            default => $this->safeHref($payload['url'] ?? $payload['link'] ?? null),
        };
    }

    /** @return array<string, mixed> */
    private function connectionPayload(IntegrationConnection $conn): array
    {
        return is_array($conn->payload) ? $conn->payload : [];
    }

    /**
     * The earliest active connection across one or more platform slugs (a
     * single slug for the common case; multiple for reservations' three
     * interchangeable providers). Plain-array grouping — deliberately not an
     * Eloquent Collection groupBy/flatten chain, whose nested-collection
     * type juggling misbehaves here.
     *
     * @param  array<string, list<IntegrationConnection>>  $byPlatform
     * @param  list<string>  $slugs
     */
    private function earliestConnection(array $byPlatform, array $slugs): ?IntegrationConnection
    {
        $candidates = [];
        foreach ($slugs as $slug) {
            foreach ($byPlatform[$slug] ?? [] as $conn) {
                $candidates[] = $conn;
            }
        }
        if ($candidates === []) {
            return null;
        }
        usort($candidates, static fn (IntegrationConnection $a, IntegrationConnection $b): int => ($a->created_at ?? '') <=> ($b->created_at ?? ''));

        return $candidates[0];
    }

    /**
     * The harvested URL of a booking connection that exists but has no
     * publishable content yet — an auto-seeded fresha row is {url,
     * selection:null}, so its Services page is empty and presentPageIds()
     * withholds it. The link itself is real and works, so visitors keep a
     * working Book-now rather than losing the action entirely while the owner
     * finishes setup.
     *
     * Fresha only: square's connect stores a url and nothing else, so a square
     * row is complete by definition and never reaches this path.
     *
     * @param  array<string, list<IntegrationConnection>>  $connectionsByPlatform
     */
    private function incompleteBookingUrl(array $connectionsByPlatform): ?string
    {
        $descriptor = app(PlatformRegistry::class)->get('fresha');
        if ($descriptor === null) {
            return null;
        }

        foreach ($connectionsByPlatform['fresha'] ?? [] as $conn) {
            if (! $descriptor->isComplete($conn)) {
                return $this->safeHref($this->connectionPayload($conn)['url'] ?? null);
            }
        }

        return null;
    }

    /**
     * created_at per live links-group block, keyed by block id — one pluck,
     * reused for both the platform-fallback and custom-link paths above.
     * getLinks() projects created_at away, so this is the cheapest way back to it.
     *
     * @return array<string, string>
     */
    private function linkBlockCreatedAt(Site $site): array
    {
        return Block::query()
            ->where('site_id', $site->id)
            ->where('block_group', 'links')
            ->whereNull('deleted_at')
            ->get(['id', 'created_at'])
            ->mapWithKeys(fn (Block $b): array => [(string) $b->id => (string) $b->created_at])
            ->all();
    }

    // ── Wire resolution ─────────────────────────────────────────────────

    /**
     * The final ordered action list the lander renders (top 6 of). Smart mode:
     * stored action ranks resolved against the live pool (stale ids dropped),
     * then not-yet-scored pool entries appended by prior (a just-connected
     * platform shows up before the next 15-min compute tick, score null).
     * Manual mode (D6): strict curation — the owner's list resolved in order,
     * unknown ids dropped, nothing auto-appended.
     *
     * @param  list<array<string, mixed>>  $pool
     * @param  list<array{key: string, score: float, rank: int}>  $stored
     * @param  list<array<string, mixed>>  $manualActions
     * @return list<array<string, mixed>>
     */
    public function resolveRankedActions(array $pool, array $stored, bool $smartActions, array $manualActions): array
    {
        $byId = [];
        foreach ($pool as $entry) {
            $byId[$entry['id']] = $entry;
        }

        if (! $smartActions) {
            $out = [];
            foreach ($manualActions as $manual) {
                if (! is_array($manual)) {
                    continue;
                }
                if (($manual['kind'] ?? null) === 'custom') {
                    $label = is_string($manual['label'] ?? null) ? trim($manual['label']) : '';
                    // Defense-in-depth on the EMIT path — see toWire()'s same gate.
                    $url = $this->safeHref($manual['url'] ?? null);
                    if ($label === '' || $url === null) {
                        continue;
                    }
                    if (mb_strlen($label) > self::CUSTOM_LABEL_MAX) {
                        $label = mb_substr($label, 0, self::CUSTOM_LABEL_MAX);
                    }
                    $out[] = [
                        'id' => null, 'kind' => 'custom', 'label' => $label, 'url' => $url,
                        'pageId' => null, 'platform' => null, 'score' => null,
                    ];

                    continue;
                }
                $entry = $byId[(string) ($manual['ref'] ?? '')] ?? null;
                if ($entry !== null) {
                    $out[] = $this->toWire($entry);
                }
            }

            return $out;
        }

        $out = [];
        $used = [];
        foreach ($stored as $row) {
            $entry = $byId[$row['key']] ?? null;
            if ($entry === null) {
                continue; // stale stored id — no longer in the pool
            }
            $used[$row['key']] = true;
            $out[] = $this->toWire($entry, $row['score']);
        }

        // Cold-path append: pool entries the scoring job hasn't stored yet,
        // prior-ordered (deterministic id tiebreak).
        $rest = array_values(array_filter(
            $pool,
            static fn (array $entry): bool => ! isset($used[$entry['id']]),
        ));
        usort($rest, static function (array $a, array $b): int {
            $cmp = ActionVocabulary::priorFor($b['id']) <=> ActionVocabulary::priorFor($a['id']);

            return $cmp !== 0 ? $cmp : strcmp((string) $a['id'], (string) $b['id']);
        });
        foreach ($rest as $entry) {
            $out[] = $this->toWire($entry);
        }

        return $out;
    }

    /**
     * Public wire projection of a pool entry. score: blended comparable
     * score, null = not yet scored.
     *
     * @param  array<string, mixed>  $entry
     * @return array{id: string, kind: string, label: string, pageId: string|null, url: string|null, platform: string|null, score: float|null}
     */
    public function toWire(array $entry, ?float $score = null): array
    {
        // Same emit-path scheme gate as the manual-custom branch — an
        // external href ultimately traces to a user-set connection/link, so
        // keep the wire http(s)-only regardless of which writer populated it.
        $url = $this->safeHref($entry['url'] ?? null);

        return [
            'id' => (string) $entry['id'],
            'kind' => (string) $entry['kind'],
            'label' => (string) $entry['label'],
            'pageId' => $entry['pageId'] ?? null,
            'url' => $url,
            'platform' => $entry['platform'] ?? null,
            'score' => $score !== null ? round($score, 4) : null,
        ];
    }

    /**
     * Emit-path href gate: return the trimmed URL only when its scheme is
     * http/https, else null. Parses the scheme (fail-closed — a missing/
     * malformed scheme, javascript:, data:, or a relative/scheme-less URL all
     * return null) so no non-navigational URL can land in the payload as a
     * button href, regardless of which writer populated the source.
     */
    private function safeHref(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return ($scheme === 'http' || $scheme === 'https') ? $url : null;
    }

    // ── Ordering settings ───────────────────────────────────────────────

    /**
     * The four ordering preferences from site.sites.settings with defaults
     * applied (absent = smart). Shape mirrors the stored snake_case keys.
     *
     * @return array{smart_page_order: bool, manual_page_order: list<string>, smart_actions: bool, manual_actions: list<array<string, mixed>>}
     */
    public function orderingSettings(?Site $site): array
    {
        $settings = is_array($site?->settings) ? $site->settings : [];

        return [
            'smart_page_order' => filter_var($settings['smart_page_order'] ?? true, FILTER_VALIDATE_BOOL),
            // Normalize legacy page-ids (e.g. a manual order saved as 'book' before
            // the 2026-07-13 Services rename) so they still intersect present pages.
            'manual_page_order' => array_values(array_map(
                static fn (string $id): string => SitepageId::normalizePageId($id),
                array_filter(
                    is_array($settings['manual_page_order'] ?? null) ? $settings['manual_page_order'] : [],
                    'is_string',
                ),
            )),
            'smart_actions' => filter_var($settings['smart_actions'] ?? true, FILTER_VALIDATE_BOOL),
            'manual_actions' => array_values(array_filter(
                is_array($settings['manual_actions'] ?? null) ? $settings['manual_actions'] : [],
                'is_array',
            )),
        ];
    }

    /**
     * camelCase wire projection of orderingSettings() for the public payload
     * + the dashboard picker endpoint.
     *
     * @param  array{smart_page_order: bool, manual_page_order: list<string>, smart_actions: bool, manual_actions: list<array<string, mixed>>}  $settings
     * @return array{smartPageOrder: bool, manualPageOrder: list<string>, smartActions: bool, manualActions: list<array<string, mixed>>}
     */
    public function orderingWire(array $settings): array
    {
        return [
            'smartPageOrder' => $settings['smart_page_order'],
            'manualPageOrder' => $settings['manual_page_order'],
            'smartActions' => $settings['smart_actions'],
            'manualActions' => $settings['manual_actions'],
        ];
    }

    /**
     * Manual page order applied against the LIVE present pages: manual ∩
     * present in manual order (unknown/absent ids dropped), then present pages
     * missing from the manual list appended in canonical order — every present
     * page stays reachable.
     *
     * @param  list<string>  $present  presence-gated canonical page-ids
     * @param  list<string>  $manual  the stored manual_page_order
     * @return list<string>
     */
    public function applyManualPageOrder(array $present, array $manual): array
    {
        $presentSet = array_flip($present);

        $ordered = [];
        foreach ($manual as $page) {
            if (isset($presentSet[$page]) && ! in_array($page, $ordered, true)) {
                $ordered[] = $page;
            }
        }
        foreach ($present as $page) {
            if (! in_array($page, $ordered, true)) {
                $ordered[] = $page;
            }
        }

        return $ordered;
    }

    private function entry(
        string $id,
        string $kind,
        string $label,
        ?string $pageId = null,
        ?string $url = null,
        ?string $platform = null,
        mixed $createdAt = null,
    ): array {
        return [
            'id' => $id,
            'kind' => $kind,
            'label' => $label,
            'pageId' => $pageId,
            'url' => $url,
            'platform' => $platform,
            'sourceCreatedAt' => $createdAt !== null ? (string) $createdAt : null,
        ];
    }
}
