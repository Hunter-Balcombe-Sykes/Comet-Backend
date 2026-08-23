<?php

namespace App\Http\Requests\Concerns;

use App\Enums\SitepageId;
use App\Site\Actions\ActionId;
use App\Site\Actions\ActionSettings;
use Closure;
use Illuminate\Validation\Rule;

// Shared ordering-preferences validation used by both UpdateSiteRequest (user
// endpoint) and StaffUpdateSiteRequest (staff endpoint). Extraction prevents
// the two classes from drifting — a staff edit must not be able to write an
// ordering payload the user endpoint would reject. To change an ordering
// rule: update this trait only — both request classes pick it up.
//
// Unified actions (2026-08-23, spec §4):
//   settings.actions    = { mode: newest|smart|manual, slots: [{position, id}] }
//                         smart/newest: slots are LOCKS (sparse positions);
//                         manual: slots ARE the list (contiguous from 0).
//   settings.pool_order = { <pool>: newest|smart|manual }  — events/reviews
//                         never accept a mode.
//   settings.smart_page_order / manual_page_order — the page-order pair.
// The retired keys (smart_actions, manual_actions, manual_order_pools) are
// stripped before validation so a stale client can neither persist nor 422 on
// them — they were dropped from every row by the unified-actions migration.
trait SiteOrderingValidationRules
{
    /** Settings keys retired 2026-08-23 — silently stripped from any write. */
    private const RETIRED_ORDERING_KEYS = ['smart_actions', 'manual_actions', 'manual_order_pools'];

    /**
     * @return array<string, mixed>
     */
    protected function orderingRules(): array
    {
        $slots = (int) config('partna.actions.slots', 10);

        return [
            'settings.smart_page_order' => ['sometimes', 'boolean'],
            'settings.manual_page_order' => ['sometimes', 'array', 'max:16'],
            'settings.manual_page_order.*' => ['string', 'distinct', Rule::in(SitepageId::canonicalOrder())],

            'settings.actions' => ['sometimes', 'array:mode,slots'],
            'settings.actions.mode' => ['required_with:settings.actions', 'string', Rule::in(ActionSettings::MODES)],
            'settings.actions.slots' => ['sometimes', 'array', 'max:'.$slots, $this->manualSlotsContiguousRule()],
            'settings.actions.slots.*' => ['array:position,id'],
            'settings.actions.slots.*.position' => ['required', 'integer', 'min:0', 'max:'.($slots - 1), 'distinct'],
            'settings.actions.slots.*.id' => ['required', 'string', 'regex:/'.ActionId::PATTERN.'/', 'distinct'],

            'settings.pool_order' => ['sometimes', 'array', $this->poolOrderKeysRule()],
            'settings.pool_order.*' => ['string', Rule::in(ActionSettings::MODES)],

            // Per-pool locks (2026-08-23, the dashboard's "Lock in position"):
            // { <pool>: [{position, id}] }, applied in newest/smart only.
            'settings.pool_locks' => ['sometimes', 'array', $this->poolOrderKeysRule()],
            'settings.pool_locks.*' => ['array', 'max:'.ActionSettings::POOL_LOCKS_MAX],
            'settings.pool_locks.*.*' => ['array:position,id'],
            'settings.pool_locks.*.*.position' => ['required', 'integer', 'min:0', 'max:999', 'distinct'],
            'settings.pool_locks.*.*.id' => ['required', 'string', 'max:64', 'distinct'],
        ];
    }

    /**
     * Normalise an incoming settings block before validation: legacy page-ids
     * in manual_page_order ('book' → 'services') and the retired ordering
     * keys stripped. Call from prepareForValidation.
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
        foreach (self::RETIRED_ORDERING_KEYS as $key) {
            unset($settings[$key]);
        }

        return $settings;
    }

    /** In manual mode the slots must be exactly positions 0..n-1. */
    private function manualSlotsContiguousRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value) || $this->input('settings.actions.mode') !== 'manual') {
                return;
            }
            $positions = [];
            foreach ($value as $slot) {
                if (is_array($slot) && is_int($slot['position'] ?? null)) {
                    $positions[] = $slot['position'];
                }
            }
            sort($positions);
            if ($positions !== range(0, count($positions) - 1) && $positions !== []) {
                $fail('Manual slots must be contiguous from position 0.');
            }
        };
    }

    /** Every pool_order key must be a pool that accepts a mode. */
    private function poolOrderKeysRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value)) {
                return;
            }
            foreach (array_keys($value) as $pool) {
                if (! in_array($pool, ActionSettings::POOL_ORDER_KEYS, true)) {
                    $fail("The pool [{$pool}] does not accept an order mode.");

                    return;
                }
            }
        };
    }
}
