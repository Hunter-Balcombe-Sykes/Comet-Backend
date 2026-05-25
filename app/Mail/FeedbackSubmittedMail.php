<?php

namespace App\Mail;

use App\Models\Core\Feedback;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

/**
 * Internal notification email to the team@ alias when a user submits feedback.
 *
 * The subject is built from a fixed prefix + enum kind + a short id slice —
 * raw user-controlled `message` is never interpolated into the subject
 * (defence-in-depth against CRLF / header injection; centralised CRLF strip
 * also runs in BaseTransactionalMail::subject()).
 *
 * ReplyTo is set to the user's reply_email when provided and valid; otherwise
 * the base envelope's default reply-to remains in effect. The body uses Blade
 * `{{ }}` everywhere — no `{!! !!}` — so the message is HTML-escaped at render.
 */
class FeedbackSubmittedMail extends BaseTransactionalMail
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Feedback $feedback,
        public readonly ?string $userEmail,
    ) {}

    public function build(): self
    {
        // Hard-coded allowlist so a future CHECK relaxation that lets a CRLF-
        // bearing `kind` through cannot inject email headers. BaseTransactionalMail
        // also strips CRLF in subject(), but defence-in-depth.
        $kindLabel = match ($this->feedback->kind) {
            'bug' => 'Bug',
            'idea' => 'Idea',
            'praise' => 'Praise',
            'question' => 'Question',
            default => 'Other',
        };
        $idSlice = substr((string) $this->feedback->id, 0, 8);

        $mail = $this->buildEnvelope()
            ->subject("[Partna Feedback] {$kindLabel} ({$idSlice})")
            ->view('emails.feedback-submitted', [
                'feedback' => $this->feedback,
                'userEmail' => $this->userEmail,
            ]);

        $replyEmail = $this->feedback->reply_email ?: $this->userEmail;
        if (is_string($replyEmail)
            && filter_var($replyEmail, FILTER_VALIDATE_EMAIL) !== false) {
            $mail->replyTo($replyEmail);
        }

        return $mail;
    }
}
