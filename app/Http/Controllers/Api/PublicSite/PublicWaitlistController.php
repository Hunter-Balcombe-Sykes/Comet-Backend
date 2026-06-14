<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\HashesClientData;
use App\Http\Requests\Api\PublicSite\PublicWaitlistSignupRequest;
use App\Models\Core\Waitlist\WaitlistSignup;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// V2: Captures waitlist signups with applicant type, industry, team size, and pilot program opt-in.
class PublicWaitlistController extends ApiController
{
    use HashesClientData;

    public function store(PublicWaitlistSignupRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Bot protection — mirrors PublicCustomerLeadController.

        // 1) Honeypot: pretend success so bots can't fingerprint the gate.
        $honeypot = $data['website'] ?? null;
        if (is_string($honeypot) && trim($honeypot) !== '') {
            Log::info('waitlist.honeypot_hit', ['email_hash' => hash('sha256', mb_strtolower(trim((string) ($data['email'] ?? ''))))]);

            return $this->success(['ok' => true], 201);
        }

        // 2) Timing check: enforce only when form_started_at_ms is present.
        //    (Field is nullable until the frontend sends it — tighten to required then.)
        $startedMs = $data['form_started_at_ms'] ?? null;
        if (is_int($startedMs)) {
            $nowMs = (int) floor(microtime(true) * 1000);
            $delta = $nowMs - $startedMs;
            $minMs = (int) config('partna.form_timing.min_ms', 2500);
            $maxMs = (int) config('partna.form_timing.max_ms', 12 * 60 * 60 * 1000);

            if ($delta < $minMs || $delta > $maxMs) {
                return $this->error('Invalid submission.', 422);
            }
        }

        $email = mb_strtolower(trim((string) $data['email']));
        $submittedAt = now();

        // Email-only signups (e.g. coming-soon landing) leave most fields null; full
        // waitlist form submissions fill them in. Both paths flow through this payload.
        $payload = [
            'name' => $data['name'] ?? null,
            'email' => $email,
            'email_lc' => $email,
            'phone' => $data['phone'] ?? null,
            'applicant_type' => $data['type'] ?? null,
            'applicant_type_other' => $data['type_other_text'] ?? null,
            'industry' => $data['industry'] ?? null,
            'industry_other' => $data['industry_other_text'] ?? null,
            'pilot_program_opt_in' => (bool) ($data['pilot_program_opt_in'] ?? false),
            'number_of_team_members' => $data['number_of_team_members'] ?? null,
            'consent_source' => 'waitlist_form',
            'consent_ip_hash' => $this->hashIp($request->ip()),
            'consent_user_agent' => mb_substr((string) ($request->userAgent() ?? ''), 0, 500) ?: null,
            'last_submitted_at' => $submittedAt,
        ];

        $signup = $this->upsertWaitlistSignup($email, $payload);

        return $this->success(['ok' => true], $signup->wasRecentlyCreated ? 201 : 200);
    }

    private function upsertWaitlistSignup(string $emailLc, array $payload): WaitlistSignup
    {
        $wasInserted = DB::table('core.waitlist_signups')
            ->where('email_lc', $emailLc)
            ->doesntExist();

        WaitlistSignup::query()->upsert(
            [array_merge($payload, ['id' => (string) Str::uuid()])],
            ['email_lc'],
            array_keys(array_diff_key($payload, array_flip(['email_lc'])))
        );

        $signup = WaitlistSignup::query()->where('email_lc', $emailLc)->firstOrFail();

        // Simulate wasRecentlyCreated for status code logic
        if ($wasInserted) {
            // Use reflection or a flag since upsert doesn't set wasRecentlyCreated
            $signup->wasRecentlyCreated = true;
        }

        return $signup;
    }
}
