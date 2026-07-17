<?php

namespace App\Services\EarlyAccess;

use App\Mail\EarlyAccess\EarlyAccessInviteMail;
use App\Mail\EarlyAccess\EarlyAccessThankYouMail;
use App\Models\Core\EarlyAccess\EarlyAccessSignup;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Early-access lifecycle: public waitlist signups, staff invites, and
 * bootstrap-side signed-up marking. Statuses only move FORWARD
 * (waitlist → invited → signed_up); no path downgrades.
 */
class EarlyAccessService
{
    /**
     * Public marketing-form signup (upsert by email). Queues the thank-you
     * email on FIRST create only. Fields refresh only while the row is still
     * on the waitlist — an invited/signed-up row never loses its state to a
     * repeat form submission.
     *
     * @param  array{email:string, type:string, workplace_or_industry?:?string, platforms?:array, consent_ip_hash?:?string, consent_user_agent?:?string}  $data
     * @return array{signup: EarlyAccessSignup, created: bool}
     */
    public function signupFromMarketing(array $data): array
    {
        $emailLc = mb_strtolower(trim($data['email']));

        // Race-safe upsert on the email_lc UNIQUE constraint: firstOrCreate ->
        // createOrFirst catches the UniqueConstraintViolationException a concurrent
        // double-submit throws on the INSERT and re-fetches the winner off the write
        // PDO instead of 500ing.
        $signup = EarlyAccessSignup::query()->firstOrCreate(
            ['email_lc' => $emailLc],
            [
                'email' => $emailLc,
                'type' => $data['type'],
                'workplace_or_industry' => $data['workplace_or_industry'] ?? null,
                'platforms' => array_values($data['platforms'] ?? []),
                'status' => EarlyAccessSignup::STATUS_WAITLIST,
                'source' => 'marketing',
                'consent_ip_hash' => $data['consent_ip_hash'] ?? null,
                'consent_user_agent' => $data['consent_user_agent'] ?? null,
            ],
        );

        if ($signup->wasRecentlyCreated) {
            Mail::queue(new EarlyAccessThankYouMail($emailLc));

            return ['signup' => $signup, 'created' => true];
        }

        // Existing row (including a race loser re-fetched above): refresh fields
        // only while still on the waitlist — an invited/signed-up row never loses
        // its state to a repeat (or racing) form submission.
        if ($signup->status === EarlyAccessSignup::STATUS_WAITLIST) {
            $signup->fill([
                'type' => $data['type'],
                'workplace_or_industry' => $data['workplace_or_industry'] ?? $signup->workplace_or_industry,
                'platforms' => array_values($data['platforms'] ?? ($signup->platforms ?? [])),
            ])->save();
        }

        return ['signup' => $signup, 'created' => false];
    }

    /**
     * Invite one signup row: mint a fresh token (sha256 at rest), flip status
     * to invited, queue the invite email with the finish-signup link.
     * Re-inviting an already-invited row re-mints the token (old link dies) —
     * useful for resends. signed_up rows are skipped (no-op, returns null).
     */
    public function invite(EarlyAccessSignup $signup, ?string $invitedBy = null): ?string
    {
        if ($signup->status === EarlyAccessSignup::STATUS_SIGNED_UP) {
            return null;
        }

        $token = Str::random(48);

        $signup->fill([
            'status' => EarlyAccessSignup::STATUS_INVITED,
            'invited_at' => now(),
            'invite_token_hash' => hash('sha256', $token),
        ]);
        $signup->invited_by = $invitedBy;
        $signup->save();

        Mail::queue(new EarlyAccessInviteMail(
            $signup->getAttribute('email'),
            $this->signupUrl($token),
        ));

        return $token;
    }

    /**
     * Bootstrap completed for an invited email — mark the row signed_up and
     * burn the token. Safe to call for non-invitees (no-op).
     */
    public function markSignedUp(string $email): void
    {
        try {
            EarlyAccessSignup::query()
                ->where('email_lc', mb_strtolower(trim($email)))
                ->whereIn('status', [EarlyAccessSignup::STATUS_WAITLIST, EarlyAccessSignup::STATUS_INVITED])
                ->update([
                    'status' => EarlyAccessSignup::STATUS_SIGNED_UP,
                    'signed_up_at' => now(),
                    'invite_token_hash' => null,
                    'updated_at' => now(),
                ]);
        } catch (\Throwable $e) {
            // Bookkeeping only — a failed status flip must never fail the signup
            // itself. (Also covers SQLite test mirrors without the table.)
            Log::warning('early_access.mark_signed_up_failed', ['error' => $e->getMessage()]);
        }
    }

    public function signupUrl(string $token): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/signup?invite='.$token;
    }
}
