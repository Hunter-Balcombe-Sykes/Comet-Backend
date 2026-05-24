<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\BootstrapRequest;
use App\Http\Resources\ProfessionalDashboardResource;
use App\Models\Core\Professional\User;
use App\Models\Core\Waitlist\WaitlistSignup;
use App\Services\Professional\ProfessionalBootstrapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;

// V2: Account signup/update. Keeps waitlist gating + individual-waitlist
// divert + HTTP shaping in the controller; delegates the create-or-update
// transaction to ProfessionalBootstrapService.
class BootstrapController extends ApiController
{
    public function __construct(
        private readonly ProfessionalBootstrapService $bootstrapService,
    ) {}

    public function bootstrap(BootstrapRequest $request): JsonResponse
    {
        $uid = $request->attributes->get('supabase_uid');
        if (! is_string($uid) || $uid === '') {
            return $this->error('Unauthenticated', 401);
        }

        if ($this->isWaitlistModeEnabled() && ! $this->hasExistingProfessional($uid)) {
            return $this->error(
                'New account creation is currently waitlist-only. Please join the waitlist.',
                403,
                ['code' => 'WAITLIST_ONLY']
            );
        }

        // §28.14 — Individual waitlist diversion (CFG-1). Runs BEFORE validation
        // so a divert never creates a Professional row. Payload is intentionally
        // minimal: email + applicant_type='individual' + consent_source. Other
        // columns are nullable post-migration 20260524120000.
        if (
            (bool) config('partna.individual_waitlist_enabled', false)
            && ! $this->hasExistingProfessional($uid)
        ) {
            $emailLc = strtolower(trim((string) $request->input('primary_email', '')));
            if ($emailLc !== '') {
                $firstName = trim((string) $request->input('first_name', ''));
                $lastName = trim((string) $request->input('last_name', ''));
                $name = trim($firstName.' '.$lastName);

                WaitlistSignup::query()->updateOrCreate(
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

        try {
            $result = $this->bootstrapService->bootstrap($uid, $data);
        } catch (RuntimeException $e) {
            return $this->translateBootstrapException($e, $uid, $data['primary_email'] ?? null);
        }

        return $this->success([
            'professional' => new ProfessionalDashboardResource($result['professional']),
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
