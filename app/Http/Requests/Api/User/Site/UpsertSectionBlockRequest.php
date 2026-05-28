<?php

namespace App\Http\Requests\Api\User\Site;

use App\Http\Requests\BaseFormRequest;
use App\Rules\MaxWords;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

// V2: Validates section block upsert — block type from config allowlist, title, publication state, and word-limited text for bio/promo sections.
class UpsertSectionBlockRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $type = (string) $this->input('block_type');
        $allowed = config('partna.section_block_types', []);

        $textRules = ['sometimes', 'nullable', 'string', 'max:4000'];

        // Enforce 200 words for these section types
        if (in_array($type, ['bio', 'promotional_text'], true)) {
            $textRules[] = new MaxWords(200);
        }

        $rules = [
            'block_type' => ['required', 'string', Rule::in($allowed)],
            'title' => ['sometimes', 'nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'is_enabled' => ['sometimes', 'boolean'],
            'publication_state' => ['sometimes', 'string', Rule::in(['live', 'draft'])],
            'settings' => ['sometimes', 'array'],
            'settings.text' => $textRules,

            // Newsletter section — single configurable field: the input
            // placeholder text. Public site falls back to "Sign up with
            // your email" when null. The old headline/description/cta_label
            // trio was retired in favour of a one-line signup form.
            'settings.input_placeholder' => ['sometimes', 'nullable', 'string', 'max:120'],
            // list_key routes the signup to a named mailing list. Defaults to
            // 'marketing' server-side if omitted; constrained to slug shape so
            // a free-form value can't collide with future system list keys.
            'settings.list_key' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^[a-z0-9][a-z0-9_-]{0,39}$/'],
        ];

        if ($type === 'countdown') {
            $rules = array_merge($rules, $this->countdownRules());
        }

        if ($type === 'contact') {
            $rules = array_merge($rules, $this->contactRules());
        }

        return $rules;
    }

    /**
     * Contact section — enquiry form authoring.
     *
     * notification_email is nullable here so the affiliate can save a draft
     * without it; SectionVisibilityService::checkContactRequirements gates
     * Publish on a valid email being present. subject_options is the
     * affiliate's *additions* to the platform defaults (config
     * `sidest.contact_subject_defaults`); at render + submission time the
     * two lists are merged.
     *
     * notification_channels constrains delivery to known channel identifiers;
     * the cross-field rule (withValidator) enforces that email channel selection
     * requires a notification_email to route to.
     *
     * @return array<string, array<int, mixed>>
     */
    private function contactRules(): array
    {
        return [
            'settings.headline' => ['sometimes', 'nullable', 'string', 'max:80'],
            'settings.description' => ['sometimes', 'nullable', 'string', 'max:300'],
            'settings.notification_email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'settings.notification_channels' => ['sometimes', 'array', 'min:1'],
            'settings.notification_channels.*' => ['string', Rule::in(['in_app', 'email'])],
            'settings.subject_options' => ['sometimes', 'nullable', 'array', 'max:10'],
            'settings.subject_options.*' => ['string', 'max:60', 'distinct'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        // Cross-field rule: selecting the email channel requires a destination address.
        // Checked after field-level rules so notification_channels is already coerced.
        $validator->after(function ($v) {
            $channels = (array) $this->input('settings.notification_channels', []);
            $email = (string) $this->input('settings.notification_email', '');
            if (in_array('email', $channels, true) && trim($email) === '') {
                $v->errors()->add('settings.notification_channels',
                    'Cannot select email channel without a notification_email set.');
            }
        });
    }

    /**
     * Countdown-specific settings shape. Timeline is paired (both or neither);
     * title + per-state copy/CTAs are independent. Kept in its own method to
     * keep rules() legible as more block types are added.
     *
     * @return array<string, array<int, mixed>>
     */
    private function countdownRules(): array
    {
        $rules = [
            'settings.title' => ['sometimes', 'nullable', 'string', 'max:80'],
            'settings.timeline' => ['sometimes', 'array'],
            // Timeline fields are paired: if either is present, both must be.
            // `required_with` handles that without `sometimes`; if neither key
            // is sent, Laravel simply skips these rules (they're nested under
            // `settings.timeline` which is optional).
            'settings.timeline.drop_time' => ['required_with:settings.timeline.expiry_time', 'date'],
            'settings.timeline.expiry_time' => ['required_with:settings.timeline.drop_time', 'date', 'after:settings.timeline.drop_time'],
            'settings.states' => ['sometimes', 'array'],
        ];

        // URL scheme allowlist: https?://, absolute path (not protocol-relative //),
        // or hash anchor. Rejects javascript:, data:, mailto:, and protocol-relative.
        $urlPattern = '/^(https?:\/\/\S+|\/(?!\/)\S*|#\S*)$/i';

        // Per-state copy + CTA rules: same shape for all three states, so build them
        // programmatically rather than writing 18 near-identical lines.
        foreach (['pre_drop', 'live', 'expired'] as $state) {
            $rules["settings.states.{$state}"] = ['sometimes', 'array'];
            $rules["settings.states.{$state}.headline"] = ['sometimes', 'nullable', 'string', 'max:80'];
            $rules["settings.states.{$state}.subtitle"] = ['sometimes', 'nullable', 'string', 'max:200'];
            $rules["settings.states.{$state}.cta"] = ['sometimes', 'array'];
            // CTA label + url are paired. Drop `sometimes` so `required_with`
            // fires when the counterpart IS present but this field is not.
            $rules["settings.states.{$state}.cta.label"] = [
                'nullable',
                'string',
                'max:40',
                "required_with:settings.states.{$state}.cta.url",
            ];
            $rules["settings.states.{$state}.cta.url"] = [
                'nullable',
                'string',
                'max:2048',
                "required_with:settings.states.{$state}.cta.label",
                "regex:{$urlPattern}",
            ];
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        $blockType = $this->route('blockType') ?? $this->route('block_type') ?? $this->route('type');
        if (is_string($blockType)) {
            $this->merge(['block_type' => strtolower(trim($blockType))]);
        }

        $publicationState = $this->input('publication_state');
        if (is_string($publicationState)) {
            $this->merge(['publication_state' => strtolower(trim($publicationState))]);
        }

        $settings = $this->input('settings', []);
        if (is_array($settings)) {
            $settingsChanged = false;
            // headline/description/cta_label remain in the strip-tags pass
            // for backwards-compatible cleaning of legacy payloads. The
            // newsletter validation rules no longer accept those keys —
            // input_placeholder is the only authored newsletter field
            // going forward.
            foreach (['text', 'headline', 'description', 'cta_label', 'input_placeholder'] as $field) {
                if (! array_key_exists($field, $settings) || ! is_string($settings[$field])) {
                    continue;
                }
                $settings[$field] = static::cleanString($settings[$field]);
                $settingsChanged = true;
            }
            if ($settingsChanged) {
                $this->merge(['settings' => $settings]);
            }
        }

        // Countdown sanitization — title + per-state headline/subtitle/cta.label.
        // Scoped by block_type to avoid mutating shape-equivalent keys on other
        // block types (e.g. a future block using a `title` key differently).
        if ($this->input('block_type') === 'countdown') {
            $this->sanitizeCountdownSettings();
        }
    }

    /**
     * Strip HTML tags from user-authored string fields in countdown settings.
     * Mirrors the bio/newsletter pattern: defense-in-depth against a future
     * renderer that forgets to escape. URLs are NOT stripped — tags in URLs
     * are invalid anyway, and the scheme-allowlist regex already rejects
     * dangerous values.
     */
    private function sanitizeCountdownSettings(): void
    {
        $settings = $this->input('settings', []);
        if (! is_array($settings)) {
            return;
        }

        $clean = static function (mixed $value): mixed {
            return is_string($value) ? static::cleanString($value) : $value;
        };

        if (array_key_exists('title', $settings)) {
            $settings['title'] = $clean($settings['title']);
        }

        foreach (['pre_drop', 'live', 'expired'] as $state) {
            if (! isset($settings['states'][$state]) || ! is_array($settings['states'][$state])) {
                continue;
            }

            foreach (['headline', 'subtitle'] as $field) {
                if (array_key_exists($field, $settings['states'][$state])) {
                    $settings['states'][$state][$field] = $clean($settings['states'][$state][$field]);
                }
            }

            if (isset($settings['states'][$state]['cta']) && is_array($settings['states'][$state]['cta'])) {
                if (array_key_exists('label', $settings['states'][$state]['cta'])) {
                    $settings['states'][$state]['cta']['label'] = $clean($settings['states'][$state]['cta']['label']);
                }
            }
        }

        $this->merge(['settings' => $settings]);
    }
}
