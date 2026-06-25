<?php

namespace App\Services\Feedback;

use App\Jobs\Notifications\SendFeedbackEmailJob;
use App\Models\Core\Feedback;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Feedback\Exceptions\DuplicateFeedbackException;
use App\Services\Feedback\Exceptions\FeedbackNotAllowedException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Persists validated feedback and dispatches the notification job.
 *
 * Capability gate is enforced here as defence-in-depth (the controller's
 * Policy already gates create). Duplicate-window check prevents a flood of
 * identical messages from a stuck client or double-tap.
 */
class FeedbackService
{
    /**
     * @param  array<string, mixed>  $data  Validated input from SubmitFeedbackRequest.
     */
    public function submit(User $actor, array $data, Request $request): Feedback
    {
        if (! AccountCapabilities::for($actor)->can_submit_feedback) {
            throw new FeedbackNotAllowedException;
        }

        $message = $data['message'];

        // Transaction-scoped advisory lock keyed per-actor closes the TOCTOU gap
        // between the duplicate window check and the insert. Two simultaneous
        // requests from the same user will queue at the lock — only the first
        // proceeds, and the second sees the row the first just inserted.
        //
        // Key is per-user ("feedback:{user_id}") so the lock does not globally
        // serialise all feedback submissions — only concurrent submits from the
        // same actor contend. Chosen over a UNIQUE index because the dedup is a
        // rolling window (not a permanent uniqueness constraint), and a static
        // index on (user_id, message) would permanently block a user from ever
        // re-reporting the same thing.
        //
        // pg_advisory_xact_lock is transaction-scoped: it auto-releases at
        // COMMIT or ROLLBACK — no manual unlock needed.
        $feedback = DB::transaction(function () use ($actor, $data, $message, $request) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["feedback:{$actor->id}"]);

            $duplicate = Feedback::query()
                ->where('user_id', $actor->id)
                ->where('message', $message)
                ->where('created_at', '>=', now()->subSeconds(
                    (int) config('partna.feedback.duplicate_window_seconds', 60)
                ))
                ->exists();

            if ($duplicate) {
                throw new DuplicateFeedbackException;
            }

            return Feedback::create([
                'user_id' => $actor->id,
                'reply_email' => $data['reply_email'] ?? null,
                'kind' => $data['kind'],
                'severity' => $data['severity'] ?? null,
                'message' => $message,
                'page_url' => $data['page_url'] ?? null,
                // user_agent: validated input wins, then header. Capped to 1024
                // chars to match the FormRequest rule even when sourced from the
                // header — header content is otherwise unvalidated, so the cap is
                // the only thing standing between a 64KB malicious UA and the row.
                'user_agent' => $data['user_agent'] ?? mb_substr((string) $request->userAgent(), 0, 1024) ?: null,
                'viewport' => $data['viewport'] ?? null,
                'app_version' => $data['app_version'] ?? null,
                // request_id: frontend-supplied only. VerifySupabaseJwt logs the
                // request_id but does not expose it as a request attribute.
                'request_id' => $data['request_id'] ?? null,
                'source' => 'dashboard',
                'ip_hash' => $this->hashIp($request->ip()),
            ]);
        });

        // afterCommit so a queue / mail misconfig cannot roll back the insert.
        SendFeedbackEmailJob::dispatch((string) $feedback->id)->afterCommit();

        return $feedback;
    }

    private function hashIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        $pepper = (string) config('partna.feedback.ip_hash_pepper', '');
        if ($pepper === '') {
            // Soft-degrade: PII never leaks, but the abuse-correlation index
            // becomes useless. Warn in production so a misconfigured env is
            // noticed before it matters.
            if (app()->isProduction()) {
                Log::warning('FEEDBACK_IP_HASH_PEPPER is empty — ip_hash storage disabled');
            }

            return null;
        }

        return hash('sha256', $ip.'|'.$pepper);
    }
}
