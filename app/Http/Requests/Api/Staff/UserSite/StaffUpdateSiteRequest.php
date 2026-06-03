<?php

namespace App\Http\Requests\Api\Staff\UserSite;

use App\Http\Requests\Api\User\Site\UpdateSiteRequest;
use App\Http\Requests\BaseFormRequest;
use App\Http\Requests\Concerns\DesignKitValidationRules;
use App\Models\Core\Site\Site;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

// Validates staff update of a site — skeleton selection, subdomain (with
// uniqueness + reserved-word checks), publish status, settings (non-design
// only). Per-user design vars are written via the `design_kit` field which
// is processed separately by the controller (writes to site.design_kits).
class StaffUpdateSiteRequest extends BaseFormRequest
{
    use DesignKitValidationRules;

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (is_string($this->subdomain ?? null)) {
            $merge['subdomain'] = strtolower(trim($this->subdomain));
        }

        $settings = $this->input('settings');
        if (is_array($settings)) {
            foreach (['hero_title', 'hero_subtitle', 'primary_button_text', 'bio_text'] as $field) {
                if (! array_key_exists($field, $settings) || ! is_string($settings[$field])) {
                    continue;
                }
                $settings[$field] = static::cleanString($settings[$field]);
            }
            $merge['settings'] = $settings;
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        $professional = $this->route('professional');
        $currentSiteId = $professional?->site?->id;

        return [
            // Skeleton — one of skeleton-1..4. Replaces theme_id.
            'skeleton_id' => ['sometimes', 'string', Rule::in(UpdateSiteRequest::ALLOWED_SKELETONS)],

            // Per-user design kit. Defined in DesignKitValidationRules trait so
            // this class and UpdateSiteRequest share a single source of truth
            // (TEST-5 / LIFE-4). Any design_kit column change must be made in the
            // trait only.
            ...$this->designKitRules(),

            'settings' => ['sometimes', 'array'],
            // settings.design.* is dead — reject any incoming key under it.
            'settings.design' => ['prohibited'],

            // Subdomain: staff can update with same constraints as pros
            'subdomain' => [
                'sometimes',
                'required',
                'string',
                'min:3',
                'max:63',
                'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/',
                function ($attribute, $value, $fail) use ($currentSiteId, $professional) {
                    $reserved = array_map('strtolower', config('partna.reserved_subdomains', []));
                    if (in_array(strtolower($value), $reserved, true)) {
                        $fail('The subdomain "'.$value.'" is reserved and cannot be used.');

                        return;
                    }

                    $exists = Site::whereRaw('lower(subdomain) = ?', [strtolower($value)])
                        ->when($currentSiteId, function ($query) use ($currentSiteId) {
                            $query->where('id', '!=', $currentSiteId);
                        })
                        ->exists();

                    if ($exists) {
                        $fail('This subdomain is already taken.');

                        return;
                    }

                    $aliasExists = DB::table('site.site_subdomain_aliases')
                        ->whereRaw('lower(subdomain) = ?', [strtolower($value)])
                        ->exists();

                    if ($aliasExists) {
                        $fail('This subdomain is already taken.');

                        return;
                    }

                    // Also block handles claimed by another professional's old handle alias.
                    // These are preserved for redirect/SEO purposes and must not be re-used.
                    $currentUserId = $professional?->id;

                    try {
                        $existsInUserAliases = DB::connection('pgsql')
                            ->table('core.user_handle_aliases')
                            ->whereRaw('LOWER(handle) = LOWER(?)', [$value])
                            ->where('user_id', '!=', $currentUserId)
                            ->exists();
                    } catch (QueryException $e) {
                        report($e);
                        Log::warning('Professional alias check failed in StaffUpdateSiteRequest', [
                            'error' => $e->getMessage(),
                            // $professional is the route-bound User model captured above.
                            'professional_id' => $professional?->id,
                            'operation' => 'subdomain_alias_check',
                        ]);
                        $existsInUserAliases = false;
                    }

                    if ($existsInUserAliases) {
                        $fail('This subdomain is already taken.');
                    }
                },
            ],

            'is_published' => ['sometimes', 'boolean'],

            // Non-design settings — allowlist specific keys with validation.
            'settings.hero_title' => ['sometimes', 'string', 'max:100'],
            'settings.hero_subtitle' => ['sometimes', 'string', 'max:200'],
            'settings.primary_button_text' => ['sometimes', 'string', 'max:50'],
            'settings.primary_button_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'settings.bio_text' => ['sometimes', 'nullable', 'string', 'max:500'],
            'settings.show_branding' => ['sometimes', 'boolean'],
            'settings.services_auto_sync_enabled' => ['sometimes', 'boolean'],
            'settings.booking_mode' => [
                'sometimes',
                'string',
                Rule::in(['manual']),
            ],
            'settings.manual_booking_url' => ['sometimes', 'nullable', 'url', 'max:2048'],

            // Staff-only override hatch — honoured by UpdateSiteAction when
            // options['allow_force_publish'] is true.
            'force_publish' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'subdomain.regex' => 'The subdomain must contain only lowercase letters, numbers, and hyphens, and cannot start or end with a hyphen.',
            'subdomain.unique' => 'This subdomain is already taken.',
            'subdomain.min' => 'The subdomain must be at least 3 characters.',
            'subdomain.max' => 'The subdomain cannot exceed 63 characters.',
            'settings.design.prohibited' => 'settings.design.* is no longer accepted. Use the design_kit field instead.',
            'skeleton_id.in' => 'Skeleton must be one of: skeleton-1, skeleton-2, skeleton-3, skeleton-4.',
        ];
    }
}
