<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Normalises a login identifier for the frontend to pass into Supabase
// signInWithPassword / signInWithOtp / resetPasswordForEmail. Public + throttled.
//
// Contract — always returns 200 with { "email": string|null }. An email is
// echoed back lowercased; a handle always resolves to null.
//
// SEC-1 (2026-08-17): the handle path USED to return the matching user's
// `core.users.primary_email` — their private Supabase login address, which is a
// different column from the intentionally public `public_contact_email`. Handles
// are public subdomains and enumerable by design, so that made this endpoint a
// systematic handle -> private-email harvesting vector. Its stated mitigations
// did not hold: `bot.token:login-identifier` is INERT unless
// `partna.bot_protection.mode` is set (VerifyBotToken short-circuits on 'off',
// which is the config default and unset on both Cloud environments), leaving
// only a 20/min per-IP throttle.
//
// Logging in by handle is therefore gone for now: users sign in with their email
// address. Restoring it means resolving server-side and never putting the address
// on the wire - the backend triggers the Supabase OTP / reset itself and returns
// only whether it sent. That is a cross-repo change; this is the stop-gap that
// closes the disclosure.
class PublicLoginIdentifierController extends ApiController
{
    public function resolve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
        ]);

        $input = trim((string) $validated['identifier']);

        // Email path — accept it as-is. Supabase rejects unknown emails with
        // the same error whether we pre-resolve or not, so no lookup needed.
        if (filter_var($input, FILTER_VALIDATE_EMAIL)) {
            return $this->success(['email' => mb_strtolower($input)]);
        }

        // Handle path — resolves to null, always. No lookup happens: the address
        // is never read, so there is no hit-vs-miss timing signal to equalise
        // and the previous jitter is gone with the query it was hiding.
        return $this->success(['email' => null]);
    }
}
