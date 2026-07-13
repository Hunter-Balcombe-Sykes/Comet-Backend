<?php

namespace App\Http\Requests\Concerns;

use App\Enums\SitepageId;
use Illuminate\Validation\Rule;

// Shared ordering-preferences validation (OV-I actions system) used by both
// UpdateSiteRequest (user endpoint) and StaffUpdateSiteRequest (staff endpoint).
// Extraction prevents the two classes from drifting — a staff edit must not be
// able to write an ordering payload the user endpoint would reject, in particular
// a custom action whose {label,url} URL isn't http(s) (OV-I). To change an
// ordering rule: update this trait only — both request classes pick it up.
trait SiteOrderingValidationRules
{
    /**
     * The settings.smart_page_order / manual_page_order / smart_actions /
     * manual_actions rule map. Absent = smart (the read side defaults both
     * toggles to true). The two manual lists REPLACE atomically on write — see
     * UpdateSiteAction::LIST_SETTINGS_KEYS.
     *
     * @return array<string, mixed>
     */
    protected function orderingRules(): array
    {
        return [
            'settings.smart_page_order' => ['sometimes', 'boolean'],
            'settings.manual_page_order' => ['sometimes', 'array', 'max:16'],
            'settings.manual_page_order.*' => ['string', 'distinct', Rule::in(SitepageId::canonicalOrder())],
            'settings.smart_actions' => ['sometimes', 'boolean'],
            'settings.manual_actions' => ['sometimes', 'array', 'max:12', $this->distinctActionRefsRule()],
            'settings.manual_actions.*' => ['array', $this->manualActionEntryRule()],
            'settings.manual_actions.*.kind' => ['required', 'string', Rule::in(['page', 'item', 'button', 'custom'])],
            'settings.manual_actions.*.ref' => ['sometimes', 'string', 'max:160'],
            'settings.manual_actions.*.label' => ['sometimes', 'string', 'min:1', 'max:80'],
            'settings.manual_actions.*.url' => ['sometimes', 'string', 'url:http,https', 'max:2048'],
        ];
    }

    /**
     * Normalize legacy page-ids in an incoming ordering settings block ('book' →
     * 'services' after the 2026-07-13 rename) so an older client's
     * manual_page_order / page-kind action refs validate + persist under the
     * current taxonomy. Call from prepareForValidation. Returns the settings
     * unchanged when it carries no ordering keys.
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
            $settings['manual_actions'] = array_map(
                static function ($action) {
                    if (is_array($action) && ($action['kind'] ?? null) === 'page' && is_string($action['ref'] ?? null)) {
                        $action['ref'] = SitepageId::normalizePageId($action['ref']);
                    }

                    return $action;
                },
                $settings['manual_actions'],
            );
        }

        return $settings;
    }

    /**
     * Per-entry strictness for settings.manual_actions.* — each entry is
     * EXACTLY one of:
     *   {kind: page,   ref: <taxonomy page-id>}
     *   {kind: item,   ref: "<itemType>:<itemKey>"}
     *   {kind: button, ref: <platform slug>}   ('booking' = general booking link)
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
                if (! is_string($ref) || $ref === '') {
                    $fail('The action ref is required.');
                } else {
                    $refValid = match ($kind) {
                        'page' => in_array($ref, SitepageId::canonicalOrder(), true),
                        'button' => (bool) preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $ref),
                        'item' => (bool) preg_match('/^[a-z][a-z0-9_]*:\S{1,120}$/', $ref),
                        default => true, // unknown kind — .kind Rule::in reports it
                    };
                    if (! $refValid) {
                        $fail('The action ref is not valid for its kind.');
                    }
                }
                $allowed = ['kind', 'ref'];
            }

            if (array_diff(array_keys($value), $allowed) !== []) {
                $fail('The action entry contains unknown keys.');
            }
        };
    }

    /**
     * Reject duplicate kind:ref pairs in settings.manual_actions (customs are
     * exempt — several custom buttons are legitimate).
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
                $key = ($entry['kind'] ?? '').':'.($entry['ref'] ?? '');
                if (isset($seen[$key])) {
                    $fail('Duplicate action refs are not allowed.');

                    return;
                }
                $seen[$key] = true;
            }
        };
    }
}
