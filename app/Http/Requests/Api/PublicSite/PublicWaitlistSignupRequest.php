<?php

namespace App\Http\Requests\Api\PublicSite;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

// V2: Validates waitlist signup. Only email is required — all other fields are optional
// so the public coming-soon landing can submit an email-only row, while the full
// multi-step form (when reintroduced) can submit the complete payload.
class PublicWaitlistSignupRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => $this->normalizeOptionalString($this->input('name')),
            'email' => $this->normalizeEmail($this->input('email')),
            'phone' => $this->normalizePhone($this->input('phone')),
            'type' => $this->normalizeApplicantType($this->input('type')),
            'type_other_text' => $this->normalizeOptionalString($this->input('type_other_text')),
            'industry' => $this->normalizeIndustry($this->input('industry')),
            'industry_other_text' => $this->normalizeOptionalString($this->input('industry_other_text')),
            'pilot_program_opt_in' => $this->normalizeBoolean($this->input('pilot_program_opt_in')),
            'number_of_team_members' => $this->normalizeInteger($this->input('number_of_team_members')),
            'currently_sells_products' => $this->normalizeBoolean($this->input('currently_sells_products')),
        ]);
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:255'],
            'name' => ['nullable', 'string', 'max:200'],
            'phone' => ['nullable', ...$this->phoneRule(), 'regex:/^\+?[0-9]{7,20}$/'],
            'type' => ['nullable', 'string', Rule::in(array_keys(config('partna.waitlist.types', [])))],
            'type_other_text' => ['nullable', 'string', 'max:200', 'required_if:type,other', 'prohibited_unless:type,other'],
            'industry' => ['nullable', 'string', Rule::in(array_keys(config('partna.waitlist.industries', [])))],
            'industry_other_text' => ['nullable', 'string', 'max:200', 'required_if:industry,other', 'prohibited_unless:industry,other'],
            'pilot_program_opt_in' => ['nullable', 'boolean'],
            'number_of_team_members' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'currently_sells_products' => ['nullable', 'boolean'],
        ];
    }

    private function normalizeEmail(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $normalized = mb_strtolower(trim($value));

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizePhone(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $normalized = preg_replace('/[^\d+]/', '', trim($value));
        if (! is_string($normalized) || $normalized === '') {
            return null;
        }

        if (str_contains($normalized, '+')) {
            $normalized = '+'.str_replace('+', '', $normalized);
        }

        return $normalized;
    }

    private function normalizeApplicantType(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $normalized = mb_strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }

        $compact = preg_replace('/[^a-z]+/u', '', $normalized) ?? $normalized;

        return match ($compact) {
            'professional', 'proffesional', 'profesisonal' => 'professional',
            'influencer' => 'influencer',
            'brand' => 'brand',
            'other' => 'other',
            default => str_replace([' ', '-'], '_', $normalized),
        };
    }

    private function normalizeIndustry(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $normalized = mb_strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }

        $compact = preg_replace('/[^a-z]+/u', '', $normalized) ?? $normalized;

        return match ($compact) {
            'mensgrooming' => 'mens_grooming',
            'womenshaircare' => 'womens_haircare',
            'beautyproducts' => 'beauty_products',
            'vitaminsandsupplements' => 'vitamins_and_supplements',
            'servicesandsoftware' => 'services_and_software',
            'other' => 'other',
            default => str_replace([' ', '-'], '_', $normalized),
        };
    }

    private function normalizeOptionalString(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $normalized = trim($value);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeBoolean(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed ?? $value;
    }

    private function normalizeInteger(mixed $value): mixed
    {
        if (is_int($value) || $value === null) {
            return $value;
        }

        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        return preg_match('/^-?\d+$/', $normalized) === 1
            ? (int) $normalized
            : $value;
    }
}
