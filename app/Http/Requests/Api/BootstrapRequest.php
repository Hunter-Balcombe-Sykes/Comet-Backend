<?php

namespace App\Http\Requests\Api;

use App\Http\Controllers\Concerns\DetectsClientInfo;
use App\Http\Requests\BaseFormRequest;
use App\Http\Requests\Concerns\NormalizesProfessionalType;
use App\Models\Core\Professional\Professional;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

// V2: Validates professional onboarding/bootstrap — display name, email, phone, handle generation, and professional type normalization.
class BootstrapRequest extends BaseFormRequest
{
    use DetectsClientInfo;
    use NormalizesProfessionalType;

    public function rules(): array
    {
        $uid = $this->attributes->get('supabase_uid');

        $existingProfessionalId = null;
        if (is_string($uid) && $uid !== '') {
            $existingProfessionalId = Professional::query()
                ->where('auth_user_id', $uid)
                ->value('id');
        }

        $professionalTypeRules = is_string($existingProfessionalId) && $existingProfessionalId !== ''
            ? ['sometimes', 'required']
            : ['required'];

        // Invite gate: partner signups need a brand-link context
        // (invite_token / brand_partner_professional_id / join_brand_handle /
        // brand_signup_code), otherwise we'd create an orphaned partner with no
        // brand link — no KV entry, no site.settings brand_partner — and the
        // user lands on a 404'd subdomain (see hjjh).
        //
        // Brands and individuals self-onboard and never need an invite. The
        // new `account_type` field is the explicit signal: when it's
        // 'individual' or 'brand' the invite gate is skipped. The legacy
        // `professional_type !== 'brand'` fallback stays for clients that
        // haven't migrated to the 3-state field yet — those continue to be
        // treated as partners and gated as before.
        $isFirstTimeSignup = ! is_string($existingProfessionalId) || $existingProfessionalId === '';
        $declaredType = mb_strtolower(trim((string) ($this->professional_type ?? '')));
        $declaredAccountType = mb_strtolower(trim((string) ($this->account_type ?? '')));
        $accountTypeIsSelfOnboard = $declaredAccountType === 'individual' || $declaredAccountType === 'brand';
        $requiresInvite = $isFirstTimeSignup
            && ! $accountTypeIsSelfOnboard
            && $declaredType !== ''
            && $declaredType !== 'brand';

        $inviteContextRule = $requiresInvite
            ? [
                'required_without_all:brand_partner_professional_id,join_brand_handle,brand_signup_code',
                'nullable',
                'string',
                'max:80',
            ]
            : ['sometimes', 'nullable', 'string', 'max:80'];

        $brandPartnerIdRule = $requiresInvite
            ? [
                'required_without_all:invite_token,join_brand_handle,brand_signup_code',
                'nullable',
                'uuid',
                Rule::exists('professionals', 'id'),
            ]
            : ['sometimes', 'nullable', 'uuid', Rule::exists('professionals', 'id')];

        $joinBrandHandleRule = $requiresInvite
            ? [
                'required_without_all:invite_token,brand_partner_professional_id,brand_signup_code',
                'nullable',
                'string',
                'max:50',
            ]
            : ['sometimes', 'nullable', 'string', 'max:50'];

        return [
            // Restrict to [a-z0-9_-] (matches ReclaimHandleRequest). Beyond
            // cosmetics, this keeps colons out of the handle: handle_lc is later
            // interpolated into Redis cache keys, where ':' is the namespace
            // separator — an unrestricted handle could collide key namespaces.
            'handle' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^[a-z0-9_-]+$/i'],
            'display_name' => ['required', 'string', 'max:80'],
            'primary_email' => [
                'required', 'email:rfc', 'max:255',
                Rule::unique('professionals', 'primary_email')->ignore($existingProfessionalId, 'id'),
            ],
            'phone' => ['nullable', ...$this->phoneRule()],
            'first_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            // ISO 3166-1 alpha-2 only. Lower-case and whitespace are normalised
            // in prepareForValidation, so by the time we get here the value is
            // already two upper-case letters. Nullable because the CDN-header
            // fallback (also in prepareForValidation) handles the common case
            // where the frontend doesn't explicitly collect country.
            'country_code' => ['nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'invite_token' => $inviteContextRule,
            'brand_partner_professional_id' => $brandPartnerIdRule,
            'join_brand_handle' => $joinBrandHandleRule,
            // Brand signup code — alternative to invite_token for en-masse partner onboarding.
            // Optional even for invite-only signups; the controller resolves it after invite_token fails.
            'brand_signup_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'shopify_setup_token' => ['sometimes', 'nullable', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'professional_type' => [
                ...$professionalTypeRules,
                'string',
                Rule::in(array_keys(config('partna.professional_types', []))),
            ],
            // §31.2 / §28.13 — Explicit 3-state account_type from the client.
            // Optional during the dual-write window (professional_type fallback
            // still works). When supplied, it's the source of truth for the
            // invite gate (see $requiresInvite above) and gets persisted via
            // `$initialAccountType` resolution in BootstrapController.
            'account_type' => ['sometimes', 'nullable', 'string', Rule::in(['brand', 'partner', 'individual'])],
            'handle_lc' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique('professionals', 'handle_lc')->ignore($existingProfessionalId, 'id'),
                // Also block handles that appear in the alias table — these are handles
                // previously used by other professionals and must not be re-claimed.
                function (string $attribute, mixed $value, \Closure $fail) use ($existingProfessionalId): void {
                    if (! is_string($value) || $value === '') {
                        return;
                    }

                    try {
                        // Use the pgsql connection explicitly: in test environments the default
                        // connection is redirected to a separate SQLite handle without schema
                        // attachments, so the dot-prefixed table name only resolves on pgsql.
                        $query = DB::connection('pgsql')
                            ->table('site.professional_handle_aliases')
                            ->whereRaw('LOWER(handle) = ?', [strtolower($value)]);

                        // Exclude the current professional's own aliases (re-bootstrap scenario)
                        if ($existingProfessionalId) {
                            $query->where('professional_id', '!=', $existingProfessionalId);
                        }

                        $taken = $query->exists();

                        if ($taken) {
                            $fail('This handle has already been taken.');
                        }
                    } catch (QueryException $e) {
                        // Alias table unavailable — skip the check rather than blocking signup.
                        report($e);
                        Log::warning('Handle alias check failed in BootstrapRequest', ['error' => $e->getMessage()]);
                    }
                },
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings([
            'handle', 'display_name', 'phone', 'first_name',
            'last_name', 'country_code', 'timezone', 'professional_type', 'account_type', 'invite_token', 'brand_signup_code',
        ]);
        $this->sanitizeEmails(['primary_email']);

        // For brands: force handle to match display_name
        $professionalType = mb_strtolower(trim((string) ($this->professional_type ?? '')));
        $isBrand = $professionalType === 'brand';

        $handle = $this->handle;
        if ($isBrand) {
            // Brand handle always derived from display_name to prevent duplicate brand names
            $handle = $this->generateHandleFromDisplayName($this->display_name ?? '');
        } elseif (! is_string($handle) || $handle === '') {
            $handle = $this->generateHandleFromDisplayName($this->display_name ?? '');
        }

        $merge = [
            'handle' => $handle,
            'handle_lc' => is_string($handle) ? strtolower(trim($handle)) : null,
        ];

        if ($this->exists('professional_type')) {
            $merge['professional_type'] = $this->normalizeProfessionalTypeInput($this->professional_type);
        }

        if ($this->exists('account_type')) {
            $rawAccountType = is_string($this->account_type) ? mb_strtolower(trim($this->account_type)) : null;
            $merge['account_type'] = in_array($rawAccountType, ['brand', 'partner', 'individual'], true)
                ? $rawAccountType
                : null;
        }

        if ($this->exists('invite_token')) {
            $merge['invite_token'] = is_string($this->invite_token) ? trim($this->invite_token) : null;
        }

        if ($this->exists('brand_partner_professional_id')) {
            $merge['brand_partner_professional_id'] = is_string($this->brand_partner_professional_id)
                ? trim($this->brand_partner_professional_id)
                : null;
        }

        if ($this->exists('join_brand_handle')) {
            $merge['join_brand_handle'] = is_string($this->join_brand_handle)
                ? strtolower(trim($this->join_brand_handle))
                : null;
        }

        if ($this->exists('brand_signup_code')) {
            $merge['brand_signup_code'] = is_string($this->brand_signup_code)
                ? trim($this->brand_signup_code)
                : null;
        }

        if ($this->exists('shopify_setup_token')) {
            $merge['shopify_setup_token'] = is_string($this->shopify_setup_token)
                ? trim($this->shopify_setup_token)
                : null;
        }

        // Resolve country_code: explicit request value first (uppercased),
        // then CDN header detection (Cloudflare / CloudFront / Vercel). The
        // fallback keeps non-AU affiliates from silently getting an
        // Australian Express account at Stripe onboarding time. If neither
        // source yields a valid ISO alpha-2 code, country_code stays null
        // and the Stripe createConnectAccount guard will 422 when they try
        // to connect.
        $providedCountry = is_string($this->country_code) ? strtoupper(trim($this->country_code)) : '';
        $resolvedCountry = $providedCountry !== '' ? $providedCountry : $this->detectCountryCode($this);
        if ($resolvedCountry !== null && $resolvedCountry !== '') {
            $merge['country_code'] = $resolvedCountry;
        }

        $this->merge($merge);
    }

    public function messages(): array
    {
        // All three rules trigger together for invite-only enforcement; collapse
        // the three near-identical "required_without_all" messages into one
        // user-facing string so the frontend gets a clean error to display.
        $inviteRequiredMessage = 'You need an invitation from a brand to sign up as an affiliate. Ask the brand to send you an invite link.';

        return [
            'invite_token.required_without_all' => $inviteRequiredMessage,
            'brand_partner_professional_id.required_without_all' => $inviteRequiredMessage,
            'join_brand_handle.required_without_all' => $inviteRequiredMessage,
            'brand_signup_code.required_without_all' => $inviteRequiredMessage,
        ];
    }

    // Surface invite-only signup rejections with a structured error code so the
    // frontend can route the user back to "ask a brand to invite you" UI rather
    // than showing a generic validation message buried in a toast.
    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors();
        $inviteFields = ['invite_token', 'brand_partner_professional_id', 'join_brand_handle', 'brand_signup_code'];

        $inviteOnlyFailure = collect($inviteFields)
            ->some(fn (string $f): bool => $errors->has($f)
                && collect($errors->get($f))->contains(
                    fn (string $msg): bool => str_starts_with($msg, 'You need an invitation')
                ));

        if ($inviteOnlyFailure) {
            throw new HttpResponseException(response()->json([
                'message' => 'You need an invitation from a brand to sign up as an affiliate. Ask the brand to send you an invite link.',
                'error' => 'invite_required',
                'errors' => $errors->toArray(),
            ], 422));
        }

        parent::failedValidation($validator);
    }

    private function generateHandleFromDisplayName(string $displayName): string
    {
        // Convert display name to slug (e.g., "Josh's Barbershop" -> "joshs-barbershop")
        $base = \Illuminate\Support\Str::slug($displayName);

        if ($base === '' || $base === '-') {
            $base = 'professional';
        }

        // Check if handle is available, if not append numbers
        $handle = $base;
        $attempt = 1;
        while (Professional::query()->where('handle_lc', strtolower($handle))->exists()) {
            $handle = $base.$attempt;
            $attempt++;
        }

        return $handle;
    }
}
