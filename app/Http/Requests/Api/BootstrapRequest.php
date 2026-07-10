<?php

namespace App\Http\Requests\Api;

use App\Enums\AccountType;
use App\Http\Controllers\Concerns\DetectsClientInfo;
use App\Http\Requests\BaseFormRequest;
use App\Models\Core\User\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

// V2: Validates individual professional onboarding/bootstrap — display name, email, phone, handle generation.
class BootstrapRequest extends BaseFormRequest
{
    use DetectsClientInfo;

    public function rules(): array
    {
        $uid = $this->attributes->get('supabase_uid');

        $existingUserId = null;
        if (is_string($uid) && $uid !== '') {
            $existingUserId = User::query()
                ->where('auth_user_id', $uid)
                ->value('id');
        }

        return [
            // Restrict to [a-z0-9_-] (matches ReclaimHandleRequest). Beyond
            // cosmetics, this keeps colons out of the handle: handle_lc is later
            // interpolated into Redis cache keys, where ':' is the namespace
            // separator — an unrestricted handle could collide key namespaces.
            'handle' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^[a-z0-9_-]+$/i'],
            'display_name' => ['required', 'string', 'max:80'],
            'primary_email' => [
                'required', 'email:rfc', 'max:255',
                Rule::unique(User::class, 'primary_email')->ignore($existingUserId, 'id'),
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
            // Account type chosen on the signup first step. Optional (older clients
            // omit it → defaults to Partna in the bootstrap service); 'individual'
            // and 'staff' are intentionally not accepted (staff rows come from
            // staff tooling only).
            'account_type' => ['sometimes', 'nullable', 'string', Rule::in([AccountType::Partna->value, AccountType::Business->value])],

            // OV-A: early-access invite token (signup?invite=<token>). Validated
            // against core.early_access_signups in the controller — a valid token
            // bypasses waitlist gating and pins primary_email to the invited address.
            'invite' => ['sometimes', 'nullable', 'string', 'max:100'],
            'handle_lc' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique(User::class, 'handle_lc')->ignore($existingUserId, 'id'),
                // Also block handles that appear in the alias table — these are handles
                // previously used by other professionals and must not be re-claimed.
                function (string $attribute, mixed $value, \Closure $fail) use ($existingUserId): void {
                    if (! is_string($value) || $value === '') {
                        return;
                    }

                    try {
                        // Use the pgsql connection explicitly: in test environments the default
                        // connection is redirected to a separate SQLite handle without schema
                        // attachments, so the dot-prefixed table name only resolves on pgsql.
                        $query = DB::connection('pgsql')
                            ->table('core.user_handle_aliases')
                            ->whereRaw('LOWER(handle) = ?', [strtolower($value)])
                            // Only ACTIVE aliases reserve a handle — an expired alias has
                            // lapsed back to the pool and is claimable by anyone.
                            ->where(function ($q) {
                                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                            });

                        // Exclude the current professional's own aliases (re-bootstrap scenario)
                        if ($existingUserId) {
                            $query->where('user_id', '!=', $existingUserId);
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
            'last_name', 'country_code', 'timezone',
        ]);

        // OV-A hardening: the authoritative signup email is the VERIFIED Supabase
        // JWT `email` claim (set as a request attribute by VerifySupabaseJwt), not
        // the request body. Binding it here — before validation, the invite
        // email-match, and the waitlist divert all read primary_email — closes the
        // hole where an attacker-controlled body email could satisfy the personal
        // invite match or seed a divert row under someone else's address. A
        // missing/blank claim email (phone-only / anonymous tokens) falls back to
        // the body value, preserving the pre-existing normal-signup behaviour.
        $claims = $this->attributes->get('supabase_claims');
        $claimEmail = is_array($claims) ? trim((string) ($claims['email'] ?? '')) : '';
        if ($claimEmail !== '') {
            $this->merge(['primary_email' => $claimEmail]);
        }

        $this->sanitizeEmails(['primary_email']);

        $handle = $this->handle;
        if (! is_string($handle) || trim($handle) === '') {
            $handle = $this->generateHandleFromDisplayName($this->display_name ?? '');
        }

        $merge = [
            'handle' => $handle,
            'handle_lc' => is_string($handle) ? strtolower(trim($handle)) : null,
        ];

        // Resolve country_code: explicit request value first (uppercased),
        // then CDN header detection (Cloudflare / CloudFront / Vercel).
        $providedCountry = is_string($this->country_code) ? strtoupper(trim($this->country_code)) : '';
        $resolvedCountry = $providedCountry !== '' ? $providedCountry : $this->detectCountryCode($this);
        if ($resolvedCountry !== null && $resolvedCountry !== '') {
            $merge['country_code'] = $resolvedCountry;
        }

        // Normalise account_type so case/whitespace from older clients still
        // matches the Rule::in allow-list.
        if (is_string($this->account_type) && trim($this->account_type) !== '') {
            $merge['account_type'] = strtolower(trim($this->account_type));
        }

        $this->merge($merge);
    }

    private function generateHandleFromDisplayName(string $displayName): string
    {
        // Convert display name to slug (e.g., "Josh's Barbershop" -> "joshs-barbershop")
        $base = Str::slug($displayName);

        if ($base === '' || $base === '-') {
            $base = 'professional';
        }

        // Check if handle is available, if not append numbers
        $handle = $base;
        $attempt = 1;
        while (User::query()->where('handle_lc', strtolower($handle))->exists()) {
            $handle = $base.$attempt;
            $attempt++;
        }

        return $handle;
    }
}
