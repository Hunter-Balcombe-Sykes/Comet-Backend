<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\BootstrapRequest;
use App\Http\Resources\UserDashboardResource;
use App\Models\Core\EarlyAccess\EarlyAccessSignup;
use App\Models\Core\User\User;
use App\Models\Core\Waitlist\WaitlistSignup;
use App\Services\EarlyAccess\EarlyAccessService;
use App\Services\User\UserBootstrapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;

// V2: Account signup/update. Keeps waitlist gating + individual-waitlist
// divert + HTTP shaping in the controller; delegates the create-or-update
// transaction to UserBootstrapService. OV-A: accepts an early-access invite
// token that bypasses waitlist gates and locks the email to the invite.
class BootstrapController extends ApiController
{
    public function __construct(
        private readonly UserBootstrapService $bootstrapService,
        private readonly EarlyAccessService $earlyAccess,
    ) {}

    public function bootstrap(BootstrapRequest $request): JsonResponse
    {
        $uid = $request->attributes->get('supabase_uid');
        if (! is_string($uid) || $uid === '') {
            return $this->error('Unauthenticated', 401);
        }

        // OV-A: early-access invite token. A VALID token must match the email
        // being registered (the invite is personal) and bypasses both waitlist
        // gates below — inviting someone past the velvet rope is its purpose.
        $inviteToken = trim((string) $request->input('invite', ''));
        $invite = $inviteToken !== '' ? EarlyAccessSignup::findByInviteToken($inviteToken) : null;

        if ($inviteToken !== '' && ($invite === null || $invite->status !== EarlyAccessSignup::STATUS_INVITED)) {
            return $this->error(
                'This invite link is no longer valid.',
                422,
                ['code' => 'INVITE_INVALID']
            );
        }

        if ($invite !== null) {
            $registeringEmail = mb_strtolower(trim((string) $request->input('primary_email', '')));
            if ($registeringEmail !== $invite->email_lc) {
                return $this->error(
                    'This invite belongs to a different email address.',
                    422,
                    ['code' => 'INVITE_EMAIL_MISMATCH']
                );
            }
        }

        if ($invite === null && $this->isWaitlistModeEnabled() && ! $this->hasExistingProfessional($uid)) {
            return $this->error(
                'New account creation is currently waitlist-only. Please join the waitlist.',
                403,
                ['code' => 'WAITLIST_ONLY']
            );
        }

        // §28.14 — Individual waitlist diversion (CFG-1). Runs BEFORE validation
        // so a divert never creates a Professional row. Payload is intentionally
        // minimal: email + applicant_type='individual' + consent_source. Other
        // columns are nullable post-migration 20260526010000.
        if (
            $invite === null
            && (bool) config('partna.individual_waitlist_enabled', false)
            && ! $this->hasExistingProfessional($uid)
        ) {
            $emailLc = strtolower(trim((string) $request->input('primary_email', '')));
            if ($emailLc !== '') {
                $firstName = trim((string) $request->input('first_name', ''));
                $lastName = trim((string) $request->input('last_name', ''));
                $name = trim($firstName.' '.$lastName);

                // firstOrCreate (not updateOrCreate) — never clobber an existing row.
                // If the visitor already submitted via the full PublicWaitlistController
                // form, that row carries richer consent provenance (industry, phone,
                // consent_ip_hash, consent_source='waitlist_form'); the divert must
                // not overwrite any of it. The trade-off is that repeat diverters
                // don't get last_submitted_at bumped — acceptable analytics loss.
                WaitlistSignup::query()->firstOrCreate(
                    ['email_lc' => $emailLc],
                    [
                        'email' => $emailLc,
                        'name' => $name !== '' ? $name : null,
                        'applicant_type' => 'individual',
                        'consent_source' => 'individual_waitlist_divert',
                        'last_submitted_at' => now(),
                    ]
                );
            }

            return $this->error(
                'New individual signups are temporarily on a waitlist. We\'ll be in touch.',
                403,
                ['code' => 'INDIVIDUAL_WAITLIST']
            );
        }

        $data = $request->validated();
        unset($data['invite']); // consumed above — never a user attribute

        try {
            $result = $this->bootstrapService->bootstrap($uid, $data);
        } catch (RuntimeException $e) {
            return $this->translateBootstrapException($e, $uid, $data['primary_email'] ?? null);
        }

        // OV-A: close the early-access loop — flips waitlist/invited → signed_up
        // and burns the invite token. No-op for emails not on the list.
        if (is_string($data['primary_email'] ?? null)) {
            $this->earlyAccess->markSignedUp($data['primary_email']);
        }

        return $this->success([
            'professional' => new UserDashboardResource($result['professional']),
            'site' => $result['site'],
        ]);
    }

    private function translateBootstrapException(RuntimeException $e, string $uid, ?string $email): JsonResponse
    {
        if ($e->getMessage() === 'ACCOUNT_DISABLED') {
            return $this->error(
                'Account is disabled. Contact support.',
                403,
                ['code' => 'ACCOUNT_DISABLED']
            );
        }

        if ($e->getMessage() === 'EMAIL_ALREADY_REGISTERED') {
            Log::info('Bootstrap rejected: email already registered to another auth user', [
                'uid' => $uid,
                'email' => $email,
            ]);

            return $this->error(
                'This email is already associated with a different account. Sign in with your original method, or contact support to link accounts.',
                409,
                ['code' => 'EMAIL_ALREADY_REGISTERED']
            );
        }

        Log::error('Bootstrap transaction failed', [
            'error' => $e->getMessage(),
            'uid' => $uid,
        ]);
        throw $e;
    }

    private function isWaitlistModeEnabled(): bool
    {
        return (bool) config('partna.waitlist.enabled', false);
    }

    private function hasExistingProfessional(string $uid): bool
    {
        return User::query()
            ->where('auth_user_id', $uid)
            ->exists();
    }
}
