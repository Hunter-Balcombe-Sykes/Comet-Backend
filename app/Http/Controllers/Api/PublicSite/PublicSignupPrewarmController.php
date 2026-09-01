<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Jobs\PreAccount\PrewarmInstagramProfileJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// 9g (2026-09-01): POST /public/signup/prewarm — fire-and-forget cache warm
// of the source profile the signup form is about to build from, so the
// build's own scrape is a 900s-cache hit by the time the visitor submits.
//
// Deliberately answers the SAME 202 for every accepted request: this
// endpoint must never become a free "does this Instagram account exist"
// oracle (the profile cache is the only artefact, and only the build lane
// reads it). Abuse posture: its own tight throttle bucket + the same
// bot-token scope as the build endpoint + the job's 900s unique lock, and
// the scraper's budget ledgers cap total vendor/actor spend exactly as they
// do for real builds.
class PublicSignupPrewarmController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_type' => ['required', 'string', 'in:instagram'],
            'source_ref' => ['required', 'string', 'min:2', 'max:64', 'regex:/^@?[A-Za-z0-9._]{2,30}$/'],
        ]);

        $username = mb_strtolower(ltrim(trim((string) $validated['source_ref']), '@'));

        PrewarmInstagramProfileJob::dispatch($username);

        return $this->success(['status' => 'warming'], 202);
    }
}
