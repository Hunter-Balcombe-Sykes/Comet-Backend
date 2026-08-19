<?php

namespace App\Http\Requests\Concerns;

use App\Enums\SitepageId;
use App\Services\PublicSite\Actions\ActionVocabulary;
use App\Services\PublicSite\ItemFeedService;
use Illuminate\Validation\Rule;

// Shared ordering-preferences validation (unified actions system) used by both
// UpdateSiteRequest (user endpoint) and StaffUpdateSiteRequest (staff endpoint).
// Extraction prevents the two classes from drifting — a staff edit must not be
// able to write an ordering payload the user endpoint would reject, in particular
// a custom action whose {label,url} URL isn't http(s). To change an ordering
// rule: update this trait only — both request classes pick it up.
trait SiteOrderingValidationRules
{
    /**
     * The dashboard's nine content pools — the domain of
     * settings.manual_order_pools. Mirrors the pool registry in
     * Partna-App/components/blocks/site-page.tsx.
     *
     * @var list<string>
     */
    private const POOL_KEYS = [
        'links', 'events', 'services', 'menu', 'watch',
        'listen', 'media', 'sell', 'posts',
    ];

    /**
     * Action ref -> ActionVocabulary id, for the legacy '<kind>:<ref>' shapes
     * the pre-2026-07-23 dashboard could have persisted. Only entries that
     * genuinely renamed (not just moved kind) need a table entry — everything
     * else is handled structurally in normalizeOrderingPageIds() below.
     *
     * @var array<string, string>
     */
    private const LEGACY_BUTTON_REF_TO_ACTION_ID = [
        'booking' => 'booking-services',
        // getLinks() historically emitted the singular platform slug (matches
        // config/partna.php's social_platforms key); the new vocabulary id is
        // plural ("Apple Podcasts").
        'apple-podcast' => 'apple-podcasts',
    ];

    /**
     * The settings.smart_page_order / manual_page_order / smart_actions /
     * manual_actions rule map. Absent = smart (the read side defaults both
     * toggles to true). The two manual lists REPLACE atomically on write — see
     * UpdateSiteAction::LIST_SETTINGS_KEYS.
     *
     * manual_actions entries are exactly one of:
     *   {kind: action, ref: <ActionVocabulary id>}
     *   {kind: custom, label: 1..80, url: http(s)}
     * (pre-2026-07-23 page/item/button shapes are migrated or dropped by
     * normalizeOrderingPageIds() in prepareForValidation, before these rules run).
     *
     * @return array<string, mixed>
     */
    protected function orderingRules(): array
    {
        return [
            'settings.smart_page_order' => ['sometimes', 'boolean'],
            'settings.manual_page_order' => ['sometimes', 'array', 'max:16'],
            'settings.manual_page_order.*' => ['string', 'distinct', Rule::in(SitepageId::canonicalOrder())],
            // The dashboard's per-pool Smart order switch (2026-08-04): pools
            // listed here use the owner's MANUAL arrangement; absent pools
            // follow the engagement ranking (content_popularity_scores).
            // Sparse-by-default like the toggle stores — smart is the default,
            // so only deviations persist. Accepts every pool key even though
            // only the ranked pools currently render the switch, so a pool
            // gaining a rank needs no backend change.
            'settings.manual_order_pools' => ['sometimes', 'array', 'max:'.count(self::POOL_KEYS)],
            'settings.manual_order_pools.*' => ['string', 'distinct', Rule::in(self::POOL_KEYS)],
            'settings.smart_actions' => ['sometimes', 'boolean'],
            'settings.manual_actions' => ['sometimes', 'array', 'max:26', $this->distinctActionRefsRule()],
            'settings.manual_actions.*' => ['array', $this->manualActionEntryRule()],
            'settings.manual_actions.*.kind' => ['required', 'string', Rule::in(['action', 'custom'])],
            'settings.manual_actions.*.ref' => ['sometimes', 'string', 'max:180'],
            'settings.manual_actions.*.label' => ['sometimes', 'string', 'min:1', 'max:80'],
            'settings.manual_actions.*.url' => ['sometimes', 'string', 'url:http,https', 'max:2048'],
            // Item feed (spec 2026-08-19-item-feed-design.md §5). Same strict
            // discriminated-union posture as manual_actions; refs deliberately
            // NOT existence-checked — the resolver drops stale ones on read.
            'settings.feed_mode' => ['sometimes', 'string', Rule::in(ItemFeedService::MODES)],
            'settings.manual_feed' => ['sometimes', 'array', 'max:'.(int) config('partna.feed.manual_max', 100), $this->distinctFeedRefsRule()],
            'settings.manual_feed.*' => ['array', $this->manualFeedEntryRule()],
            'settings.manual_feed.*.kind' => ['required', 'string', Rule::in(['item', 'category'])],
            'settings.manual_feed.*.pool' => ['required', 'string', Rule::in(ItemFeedService::FEED_POOLS)],
            'settings.manual_feed.*.ref' => ['required', 'string', 'max:180'],
            'settings.manual_feed.*.items' => ['sometimes', 'array', 'max:200'],
            'settings.manual_feed.*.items.*' => ['string', 'max:180'],
        ];
    }

    /**
     * Normalize legacy shapes in an incoming ordering settings block so an
     * older client's payload (or a stale round-tripped read) still validates
     * + persists under the current taxonomy/vocabulary. Call from
     * prepareForValidation. Returns the settings unchanged when it carries no
     * ordering keys.
     *
     * manual_page_order: legacy page-ids normalized in place ('book' -> 'services').
     * manual_actions: pre-2026-07-23 {kind: page|item|button, ref} entries are
     * migrated to {kind: action, ref: <ActionVocabulary id>} where a mapping
     * exists, else DROPPED (same "unknown ref, drop it" posture the resolve
     * step already applies to anything not in the live pool) — item-kind
     * entries always drop, there is no per-item action concept anymore.
     * {kind: action, ...} and {kind: custom, ...} entries pass through as-is.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    protected function normalizeOrderingPageIds(array $settings): array
    {
        if (is_array($settings['manual_page_order'] ?? null)) {
            $settings['manual_page_order'] = array_map(
                static fn ($id) => is_string($id) ? SitepageId::normalizePageId($id) : $id,
                $settings['manual_page_order'],
            );
        }

        if (is_array($settings['manual_actions'] ?? null)) {
            $settings['manual_actions'] = array_values(array_filter(array_map(
                fn ($action) => $this->normalizeManualActionEntry($action),
                $settings['manual_actions'],
            ), static fn ($action) => $action !== null));
        }

        return $settings;
    }

    /**
     * @return array<string, mixed>|null null = drop (no mapping to the current vocabulary)
     */
    private function normalizeManualActionEntry(mixed $action): ?array
    {
        if (! is_array($action)) {
            return $action; // not our shape to fix — let the .kind rule report it
        }

        $kind = $action['kind'] ?? null;

        // Already current-shape (or malformed in a way the field rules will
        // report) — pass through untouched.
        if ($kind !== 'page' && $kind !== 'button' && $kind !== 'item') {
            return $action;
        }

        if ($kind === 'item') {
            return null; // no per-item action concept in the current vocabulary
        }

        $ref = $action['ref'] ?? null;
        if (! is_string($ref) || $ref === '') {
            return $action; // malformed — let the .ref rule report it
        }

        if ($kind === 'page') {
            $ref = SitepageId::normalizePageId($ref);
            // The old 'services' PAGE folds into the new booking-services
            // action; every other legacy page-id that also names a current
            // static action id (shop, events, menu, contact, reservations)
            // carries over unchanged.
            $ref = $ref === 'services' ? 'booking-services' : $ref;
        } else { // button
            $ref = self::LEGACY_BUTTON_REF_TO_ACTION_ID[$ref] ?? $ref;
        }

        return ActionVocabulary::isValidId($ref) ? ['kind' => 'action', 'ref' => $ref] : null;
    }

    /**
     * Per-entry strictness for settings.manual_actions.* — each entry is
     * EXACTLY one of:
     *   {kind: action, ref: <ActionVocabulary id>}
     *   {kind: custom, label: 1..80, url: http(s)}
     * Non-custom entries must not carry label/url; custom must not carry ref;
     * no unknown keys. (Type/length/url formats are covered by the sibling
     * dotted rules — this closure enforces the cross-field shape.)
     */
    private function manualActionEntryRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_array($value)) {
                return; // the 'array' rule already fails this entry
            }

            $kind = $value['kind'] ?? null;
            if (! is_string($kind)) {
                return; // .kind rules report the missing/invalid kind
            }

            if ($kind === 'custom') {
                if (array_key_exists('ref', $value)) {
                    $fail('Custom actions must not include a ref.');
                }
                if (! is_string($value['label'] ?? null) || trim((string) ($value['label'] ?? '')) === '') {
                    $fail('Custom actions require a label.');
                }
                if (! is_string($value['url'] ?? null) || trim((string) ($value['url'] ?? '')) === '') {
                    $fail('Custom actions require a url.');
                }
                $allowed = ['kind', 'label', 'url'];
            } else {
                if (array_key_exists('label', $value) || array_key_exists('url', $value)) {
                    $fail('Only custom actions may carry label/url.');
                }
                $ref = $value['ref'] ?? null;
                if (! is_string($ref) || $ref === '' || ! ActionVocabulary::isValidId($ref)) {
                    $fail('The action ref is not a recognised action.');
                }
                $allowed = ['kind', 'ref'];
            }

            if (array_diff(array_keys($value), $allowed) !== []) {
                $fail('The action entry contains unknown keys.');
            }
        };
    }

    /**
     * Reject duplicate refs in settings.manual_actions (customs are exempt —
     * several custom buttons are legitimate).
     */
    private function distinctActionRefsRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_array($value)) {
                return;
            }
            $seen = [];
            foreach ($value as $entry) {
                if (! is_array($entry) || ($entry['kind'] ?? null) === 'custom') {
                    continue;
                }
                $ref = (string) ($entry['ref'] ?? '');
                if (isset($seen[$ref])) {
                    $fail('Duplicate action refs are not allowed.');

                    return;
                }
                $seen[$ref] = true;
            }
        };
    }

    /** Duplicate (kind,pool,ref) pairs in manual_feed → 422. */
    private function distinctFeedRefsRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_array($value)) {
                return;
            }
            $seen = [];
            foreach ($value as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $key = ($entry['kind'] ?? '').'|'.($entry['pool'] ?? '').'|'.($entry['ref'] ?? '');
                if (isset($seen[$key])) {
                    $fail('The manual feed contains duplicate entries.');

                    return;
                }
                $seen[$key] = true;
            }
        };
    }

    /** item ⇒ no items list; category ⇒ items required AND pool must be a category pool. */
    private function manualFeedEntryRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_array($value)) {
                return;
            }
            $kind = $value['kind'] ?? null;
            if ($kind === 'item' && array_key_exists('items', $value)) {
                $fail('An item feed entry must not carry an items list.');
            }
            if ($kind === 'category') {
                if (! is_array($value['items'] ?? null) || $value['items'] === []) {
                    $fail('A category feed entry requires a non-empty items list.');
                }
                if (! in_array($value['pool'] ?? null, ItemFeedService::CATEGORY_POOLS, true)) {
                    $fail('Category entries are only valid for the menus and services pools.');
                }
            }
        };
    }
}
