<?php

namespace App\Jobs\Notifications;

use App\Mail\FeedbackSubmittedMail;
use App\Models\Core\Feedback;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the FeedbackSubmittedMail to every recipient in
 * config('partna.feedback.notify_emails'). Fire-and-forget — a misconfigured
 * recipient list logs a warning and returns rather than poisoning the queue.
 *
 * The Redis payload carries only the feedback UUID (no PII); the row is
 * loaded at handle() time so recipient emails never sit in queue storage.
 */
class SendFeedbackEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $maxExceptions = 2;

    public array $backoff = [30, 120, 600];

    public int $timeout = 30;

    public function __construct(
        public readonly string $feedbackId,
    ) {
        $this->onQueue(config('partna.queues.notifications', 'notifications'));
    }

    // Populated in handle() after the feedback row loads so failed() can include
    // the user_id in its log context without making a second DB query.
    private ?string $userId = null;

    public function handle(): void
    {
        $recipients = (array) config('partna.feedback.notify_emails', []);
        if (empty($recipients)) {
            Log::warning('SendFeedbackEmailJob: no recipients configured (FEEDBACK_NOTIFY_EMAILS empty)', [
                'feedback_id' => $this->feedbackId,
                'user_id' => $this->userId,
            ]);

            return;
        }

        $feedback = Feedback::query()->with('user:id,primary_email')->find($this->feedbackId);
        if ($feedback === null) {
            // A missing row is anomalous — the job is dispatched immediately after
            // the feedback row is persisted. Throw so the queue records a failed_jobs
            // entry; a silent return would mark this dropped notification as "succeeded".
            Log::warning('SendFeedbackEmailJob: feedback row not found', [
                'feedback_id' => $this->feedbackId,
                'user_id' => $this->userId,
            ]);
            throw new \RuntimeException('SendFeedbackEmailJob: feedback row not found: '.$this->feedbackId);
        }

        // Stash user_id so failed() can include it in log context without an
        // extra DB query. feedback.user_id is nullable (ON DELETE SET NULL).
        $this->userId = $feedback->user_id;

        // User model exposes the auth-email as `primary_email`; there is no
        // `email` accessor. Reading `->email` would silently return null.
        $userEmail = $feedback->user?->primary_email;
        $logContext = [
            'feedback_id' => $this->feedbackId,
            'user_id' => $this->userId,
        ];

        // One Mail::send per recipient so addresses in notify_emails do not
        // leak to each other via the To: header. Per-recipient cache key
        // makes retries idempotent at the recipient level — if Horizon retries
        // after recipient #1 delivered but #2 failed, only #2 is re-attempted.
        foreach ($recipients as $recipient) {
            $recipient = trim((string) $recipient);
            if ($recipient === '') {
                continue;
            }

            $idempotencyKey = 'feedback-email-sent:'.$this->feedbackId.':'.sha1($recipient);
            if (! Cache::add($idempotencyKey, true, 86400)) {
                // Already sent to this recipient on a prior attempt — skip.
                continue;
            }

            try {
                Mail::to($recipient)->send(new FeedbackSubmittedMail($feedback, $userEmail));
            } catch (\Throwable $e) {
                // Release the idempotency claim so the retry can attempt this
                // recipient again. Other recipients keep their successful claims.
                Cache::forget($idempotencyKey);
                Log::warning('SendFeedbackEmailJob: recipient send failed', $logContext + [
                    'recipient_sha1' => sha1($recipient),
                    'error' => $e->getMessage(),
                ]);
                throw $e;     // let the queue retry policy handle backoff
            }
        }
    }

    public function failed(\Throwable $e): void
    {
        report($e);
        Log::error('SendFeedbackEmailJob failed permanently', [
            'feedback_id' => $this->feedbackId,
            // user_id is set in handle() after the row loads; may be null if
            // the job failed before reaching that point (e.g. missing row).
            'user_id' => $this->userId,
            'error' => $e->getMessage(),
        ]);
    }
}
