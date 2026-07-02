<?php

namespace App\Http\Requests\Concerns;

// Shared bot-protection validation rules and prepare helpers for public-facing
// Form Requests. Centralising these prevents field-level drift (e.g. `required`
// vs `nullable` on form_started_at_ms) across the four public submission forms.
// Usage: add `use WithBotProtection;` then call $this->botProtectionRules() inside
// rules() and spread $this->botProtectionPrepare() inside prepareForValidation().
trait WithBotProtection
{
    /**
     * Validation rules for the two bot-protection fields.
     * Spread into the class's rules() array:
     *   return [...$this->botProtectionRules(), 'other_field' => [...], ...];
     *
     * `website` is a honeypot — must be absent or empty on legitimate submissions.
     * `form_started_at_ms` is a timing check — millisecond epoch when the form
     * was first rendered; too-fast values are rejected by the controller.
     *
     * @return array<string, list<string>>
     */
    protected function botProtectionRules(): array
    {
        return [
            'website' => ['nullable', 'string', 'max:255'],           // honeypot
            'form_started_at_ms' => ['required', 'integer', 'min:0'], // timing (epoch ms)
        ];
    }

    /**
     * Merge array for the honeypot field — spread inside prepareForValidation():
     *   $this->merge([...$this->botProtectionPrepare(), 'other_field' => ...]);
     *
     * Trimming ensures an accidental trailing space can't bypass the non-empty check.
     *
     * @return array<string, mixed>
     */
    protected function botProtectionPrepare(): array
    {
        return [
            'website' => is_string($this->website) ? trim($this->website) : $this->website,
        ];
    }
}
