<?php

namespace App\Services\User;

use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\Notifications\Notification;
use App\Models\Core\User\User;
use Illuminate\Support\Str;

// Post-write side effects shared by every path that activates a professional
// (UserBootstrapService's create branch, ClaimSiteService's first-come claim).
// Extracted verbatim from UserBootstrapService so both callers share the exact
// same idempotent insertOrIgnore semantics.
class SignupSideEffects
{
    public function ensureSidestUpdatesSubscription(?string $email): void
    {
        $email = is_string($email) ? strtolower(trim($email)) : '';
        if ($email === '') {
            return;
        }

        $now = now();

        EmailSubscription::insertOrIgnore([
            'id' => (string) Str::uuid(),
            'user_id' => null,
            'list_key' => 'sidest_updates',
            'email' => $email,
            'email_lc' => $email,
            'status' => 'subscribed',
            'subscribed_at' => $now,
            'consent_source' => 'bootstrap',
            'unsubscribe_token' => EmailSubscription::newUnsubscribeToken(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function createWelcomeNotification(User $professional): void
    {
        // Atomic dedup via the notifications_dedupe_key_per_pro_uq unique index
        // (user_id, dedupe_key): insertOrIgnore compiles to ON CONFLICT DO NOTHING, so a
        // concurrent double-bootstrap can't create two welcome rows — and, unlike a raw
        // insert that would throw, it never aborts the surrounding bootstrap transaction
        // on Postgres. Replaces the old racy firstOrCreate (LIFE-7 / DINT-12). insertOrIgnore
        // bypasses Eloquent, so id + timestamps are set explicitly.
        $now = now();

        Notification::query()->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'user_id' => $professional->id,
            'type' => 'Info',
            'title' => 'Welcome to Partna',
            'body' => 'Your account is ready. Complete your profile and start building your professional page from your dashboard.',
            'cta_url' => null,
            'severity' => 'info',
            'starts_at' => $now,
            'ends_at' => null,
            'dedupe_key' => 'welcome:'.$professional->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
