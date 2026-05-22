<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// V2: Handle/email/phone availability check during signup. Includes waitlist mode support.
class PublicSignupAvailabilityController extends ApiController
{
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
