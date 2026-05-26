<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\User\User;
use App\Services\Auth\SupabaseAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

// V2: Handle/email/phone availability check during signup. Includes waitlist mode support.
class PublicSignupAvailabilityController extends ApiController
{
    public function __construct(private readonly SupabaseAdminService $supabaseAdmin) {}

    public function check(Request $request): JsonResponse
    {
        $signupsOpen = ! (bool) config('partna.waitlist.enabled', false);

        $validated = $request->validate([
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'handle_lc' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);

        $email = isset($validated['email']) ? mb_strtolower(trim((string) $validated['email'])) : null;
        $phone = isset($validated['phone']) ? preg_replace('/[^\d+]/', '', trim((string) $validated['phone'])) : null;
        $handleLc = isset($validated['handle_lc']) ? mb_strtolower(trim((string) $validated['handle_lc'])) : null;

        $emailExists = false;
        if ($email) {
            $emailExists = User::query()
                ->where(function ($query) use ($email) {
                    $query->whereRaw('LOWER(primary_email) = ?', [$email])
                        ->orWhereRaw('LOWER(public_contact_email) = ?', [$email]);
                })
                ->exists();

            // Orphan-recovery check: a confirmed Supabase auth user with no Laravel
            // mirror means a prior signup verified the OTP but never reached bootstrap.
            // Supabase's signUp() would silently reject the next attempt
            // (user_repeated_signup, no email sent) — surface "exists" here so the
            // frontend routes the visitor to sign-in instead of trapping them on a
            // verify screen waiting for an email that won't arrive. Unconfirmed
            // Supabase users stay "available" because signUp() will resend their
            // confirmation email and let them finish.
            if (! $emailExists) {
                try {
                    $supabaseUser = $this->supabaseAdmin->findUserByEmail($email);
                    if ($supabaseUser !== null && $supabaseUser['email_confirmed_at'] !== null) {
                        $emailExists = true;
                    }
                } catch (RuntimeException $e) {
                    // Fail-open: Supabase admin API outage shouldn't block all signups.
                    // Worst case we briefly regress to the silent-enumeration bug for
                    // orphan users until the API recovers.
                    Log::warning('Signup availability: Supabase orphan check failed, falling back to Laravel-only', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $phoneExists = false;
        if ($phone) {
            $phoneExists = User::query()
                ->where(function ($query) use ($phone) {
                    $query->where('phone', $phone)
                        ->orWhere('public_contact_number', $phone);
                })
                ->exists();
        }

        $handleExists = false;
        if ($handleLc) {
            $handleExists = User::query()
                ->where('handle_lc', $handleLc)
                ->exists();
        }

        return $this->success([
            'email' => [
                'available' => ! $emailExists,
                'exists' => $emailExists,
            ],
            'phone' => [
                'available' => ! $phoneExists,
                'exists' => $phoneExists,
            ],
            'handle_lc' => [
                'available' => ! $handleExists,
                'exists' => $handleExists,
            ],
            'signups_open' => $signupsOpen,
            'waitlist_only' => ! $signupsOpen,
        ]);
    }
}
