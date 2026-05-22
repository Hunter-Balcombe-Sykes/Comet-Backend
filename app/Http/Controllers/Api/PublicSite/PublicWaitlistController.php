<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\HashesClientData;
use App\Http\Requests\Api\PublicSite\PublicWaitlistSignupRequest;
use App\Models\Core\Waitlist\WaitlistSignup;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// V2: Captures waitlist signups with applicant type, industry, team size, and pilot program opt-in.
class PublicWaitlistController extends ApiController
{
    use HashesClientData;

    public function store(PublicWaitlistSignupRequest $request): JsonResponse
    {
        $data = $request->validated();
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
