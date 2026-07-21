<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Core\EmailSuppression;
use App\Services\Notifications\EmailSuppressionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives Resend bounce/complaint webhooks and adds the affected recipient to
 * the send-time suppression list (core.email_suppressions). The MessageSending
 * gate then cancels any future mail to that address across ALL paths (OTP,
 * claim-invite, transactional).
 *
 * Signature verification is handled by the `resend.webhook` middleware — this
 * controller only runs for already-authenticated deliveries.
 *
 * Idempotency: suppression is an upsert on the recipient's email hash, so a
 * redelivered event never creates a duplicate row (no webhook_id dedup needed,
 * unlike WHK-3 whose side effect was a one-shot mail dispatch).
 *
 * Response contract: acknowledge every signature-valid delivery with 200 —
 * including unhandled event types and soft bounces — so Resend does not enter a
 * retry storm. A suppression-write failure is swallowed by the service and still
 * returns 200 (never 500 a webhook Resend will hammer).
 */
class ResendWebhookController extends Controller
{
    public function __construct(
        private readonly EmailSuppressionService $suppressions,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->json()->all();
        $type = is_string($payload['type'] ?? null) ? $payload['type'] : '';
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $recipients = $this->recipients($data);

        // A spam complaint always suppresses — the recipient explicitly marked
        // us as spam; re-sending damages the shared partna.au reputation.
        if ($type === 'email.complained') {
            $this->suppressAll($recipients, EmailSuppression::REASON_COMPLAINT, null);

            return $this->ack($type, count($recipients));
        }

        // A bounce suppresses ONLY when permanent (hard). The classifier is
        // data.bounce.type — SES-derived, surfaced by Resend as 'Permanent' vs
        // 'Transient'/'Temporary'/'Undetermined'. Suppressing a transient/soft
        // bounce (full mailbox, temporary defer) would permanently block a
        // reachable user's OTP, so we suppress on 'Permanent' only.
        // Field + values confirmed against resend.com/docs/webhooks/emails/bounced
        // (example: {"bounce":{"type":"Permanent","subType":"Suppressed"}}), 2026-07-21.
        if ($type === 'email.bounced') {
            $bounce = is_array($data['bounce'] ?? null) ? $data['bounce'] : [];
            $bounceType = is_string($bounce['type'] ?? null) ? $bounce['type'] : '';

            if (strcasecmp($bounceType, 'Permanent') === 0) {
                // subType is a non-PII classifier (e.g. 'Suppressed', 'NoEmail',
                // 'MailboxFull'). NEVER store bounce.message — it can echo the address.
                $subType = isset($bounce['subType']) && is_string($bounce['subType'])
                    ? $bounce['subType']
                    : null;
                $this->suppressAll($recipients, EmailSuppression::REASON_HARD_BOUNCE, $subType);

                return $this->ack($type, count($recipients));
            }

            Log::info('resend.webhook.soft_bounce_ignored', ['bounce_type' => $bounceType]);

            return $this->ack($type, 0);
        }

        // Unhandled event type (email.delivered, email.delivery_delayed, etc.) —
        // acknowledge so Resend does not retry.
        return $this->ack($type, 0);
    }

    /**
     * Extract recipient addresses from data.to. Resend sends an array; tolerate a
     * bare string defensively. Only data.to is suppressed — never data.from
     * (that is our own sending address).
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function recipients(array $data): array
    {
        $to = $data['to'] ?? [];
        $list = is_array($to) ? $to : [$to];

        return array_values(array_filter(array_map(
            static fn ($addr) => is_string($addr) ? trim($addr) : '',
            $list,
        ), static fn (string $addr) => $addr !== ''));
    }

    /**
     * @param  list<string>  $recipients
     */
    private function suppressAll(array $recipients, string $reason, ?string $detail): void
    {
        foreach ($recipients as $email) {
            $this->suppressions->suppress($email, $reason, 'resend', $detail);
        }
    }

    private function ack(string $type, int $suppressed): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'type' => $type,
            'suppressed' => $suppressed,
        ], 200);
    }
}
