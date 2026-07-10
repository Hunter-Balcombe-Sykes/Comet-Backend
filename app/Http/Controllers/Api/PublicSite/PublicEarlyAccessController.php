<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\HashesClientData;
use App\Http\Requests\Api\PublicSite\PublicEarlyAccessSignupRequest;
use App\Models\Core\EarlyAccess\EarlyAccessSignup;
use App\Services\EarlyAccess\EarlyAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * OV-A: public early-access endpoints — the marketing form create (rate-limited,
 * honeypot + timing guarded, mirrors PublicWaitlistController) and the invite
 * token resolve used by /signup?invite=<token> to autofill + lock the email.
 */
class PublicEarlyAccessController extends ApiController
{
    use HashesClientData;

    public function __construct(private readonly EarlyAccessService $service) {}

    /** POST /public/early-access */
    public function store(PublicEarlyAccessSignupRequest $request): JsonResponse
    {
        $data = $request->validated();

        // 1) Honeypot: pretend success so bots can't fingerprint the gate.
        $honeypot = $data['website'] ?? null;
        if (is_string($honeypot) && trim($honeypot) !== '') {
            Log::info('early_access.honeypot_hit', ['email_hash' => hash('sha256', mb_strtolower(trim((string) ($data['email'] ?? ''))))]);

            return $this->success(['ok' => true], 201);
        }

        // 2) Timing check: enforce only when form_started_at_ms is present.
        $startedMs = $data['form_started_at_ms'] ?? null;
        if (is_int($startedMs)) {
            $delta = (int) floor(microtime(true) * 1000) - $startedMs;
            $minMs = (int) config('partna.form_timing.min_ms', 2500);
            $maxMs = (int) config('partna.form_timing.max_ms', 12 * 60 * 60 * 1000);

            if ($delta < $minMs || $delta > $maxMs) {
                return $this->error('Invalid submission.', 422);
            }
        }

        $result = $this->service->signupFromMarketing([
            'email' => $data['email'],
            'type' => $data['type'],
            'workplace_or_industry' => $data['workplace_or_industry'] ?? null,
            'platforms' => $data['platforms'],
            'consent_ip_hash' => $this->hashIp($request->ip()),
            'consent_user_agent' => mb_substr((string) ($request->userAgent() ?? ''), 0, 500) ?: null,
        ]);

        return $this->success(['ok' => true], $result['created'] ? 201 : 200);
    }

    /**
     * GET /public/early-access/invite/{token}
     * Resolves an invite token to its prefill payload. Possession of the token
     * IS the authorisation (it was emailed to this address), so returning the
     * email is by design — the signup form autofills and LOCKS it.
     */
    public function resolveInvite(string $token): JsonResponse
    {
        $signup = EarlyAccessSignup::findByInviteToken($token);

        if ($signup === null || $signup->status !== EarlyAccessSignup::STATUS_INVITED) {
            // 404 for unknown AND already-consumed tokens — no enumeration signal.
            return $this->error('Invite not found.', 404);
        }

        return $this->success([
            'invite' => [
                'email' => $signup->getAttribute('email'),
                'type' => $signup->type,
                'workplace_or_industry' => $signup->workplace_or_industry,
                'platforms' => $signup->platforms ?? [],
            ],
        ]);
    }
}
