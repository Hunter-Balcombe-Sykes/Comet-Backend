<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\BootstrapRequest;
use App\Http\Resources\UserDashboardResource;
use App\Models\Core\User\User;
use App\Services\User\UserBootstrapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;

// Pre-Account Sites (Task 14): the create branch is retired — signup is
// site-first now (POST /api/public/signup/build, POST /api/claim). This
// controller ONLY refreshes an existing user's profile; a JWT with no
// core.users row 410s, pointing the caller at the new flow. The former
// invite-token / waitlist / individual-waitlist-divert gating blocks lived
// entirely inside the now-unreachable create path and were removed with it —
// UserBootstrapService's create branch stays in code (unreachable over HTTP)
// for internal reuse.
class BootstrapController extends ApiController
{
    public function __construct(
        private readonly UserBootstrapService $bootstrapService,
    ) {}

    public function bootstrap(BootstrapRequest $request): JsonResponse
    {
        $uid = $request->attributes->get('supabase_uid');
        if (! is_string($uid) || $uid === '') {
            return $this->error('Unauthenticated', 401);
        }

        // OV-A hardening: fail CLOSED when the token carries no verified email
        // claim. This route requires supabase.jwt but NOT require.email_verified,
        // so an anonymous or phone-only Supabase token (project-valid, has `sub`,
        // no `email` claim) reaches here. On this account-creation surface we must
        // never fall back to the attacker-controlled body email — otherwise such a
        // token could satisfy the personal invite email-match, pass the uniqueness
        // check, and mint a User row under a victim's address (locking them out).
        // The normal email/OAuth signup always carries a verified email claim, so
        // it is unaffected; phone-only signup, if ever wanted, is a separate
        // deliberate feature. BootstrapRequest::prepareForValidation() binds this
        // same verified claim over the body on the happy path.
        $claims = $request->attributes->get('supabase_claims');
        $verifiedEmail = is_array($claims) ? trim((string) ($claims['email'] ?? '')) : '';
        if ($verifiedEmail === '') {
            return $this->error(
                'A verified email is required to create your account.',
                422,
                ['code' => 'EMAIL_VERIFICATION_REQUIRED']
            );
        }

        // Pre-Account Sites: signup is site-first now. The create branch is
        // retired — a sub with no row builds a site (POST /api/public/signup/build)
        // and claims it (POST /api/claim). The update path below survives as the
        // idempotent profile-refresh existing users rely on. This is the only
        // gate left in the controller — the invite-token, WAITLIST_ONLY, and
        // individual-waitlist-divert blocks that used to sit here were reachable
        // ONLY for `! hasExistingProfessional($uid)` callers, so they are dead
        // code now that such callers 410 here first; removed with this change.
        if (! $this->hasExistingProfessional($uid)) {
            return $this->error(
                'Signup now starts from your site. Build it first, then claim it.',
                410,
                [],
                ['code' => 'SIGNUP_MOVED']
            );
        }

        $data = $request->validated();
        unset($data['invite']); // BootstrapRequest still accepts the field; no longer consumed

        try {
            $result = $this->bootstrapService->bootstrap($uid, $data);
        } catch (RuntimeException $e) {
            return $this->translateBootstrapException($e, $uid, $data['primary_email'] ?? null);
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

    private function hasExistingProfessional(string $uid): bool
    {
        return User::query()
            ->where('auth_user_id', $uid)
            ->exists();
    }
}
