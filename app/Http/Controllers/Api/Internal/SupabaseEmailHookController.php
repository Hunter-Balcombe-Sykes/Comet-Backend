<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Api\ApiController;
use App\Mail\Auth\EmailChangeMail;
use App\Mail\Auth\EmailConfirmMail;
use App\Mail\Auth\InviteMail;
use App\Mail\Auth\MagicLinkMail;
use App\Mail\Auth\PasswordResetMail;
use App\Mail\Auth\ReauthenticationMail;
use App\Mail\BaseTransactionalMail;
use App\Services\Notifications\SupabaseEmailEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Receives Supabase Send Email Hook events and dispatches the appropriate
 * Partna Mailable so all auth emails ride the same Resend pipeline as our
 * transactional mail. Signature is verified by the surrounding middleware.
 *
 * Payload shape (Standard Webhooks):
 *   {
 *     "user": { "email": "...", "user_metadata": { "full_name": "..." }, ... },
 *     "email_data": {
 *       "token_hash": "...",
 *       "redirect_to": "https://app.partna.au/auth/callback",
 *       "email_action_type": "signup" | "recovery" | "magiclink" | "invite" | ...,
 *       "site_url": "https://...supabase.co"
 *     }
 *   }
 */
class SupabaseEmailHookController extends ApiController
{
    public function __invoke(Request $request, SupabaseEmailEventService $eventService): JsonResponse
    {
        $payload = $request->json()->all();

        // Extract correlation IDs first so every log line in this request can
        // carry them. webhook-id is Supabase's idempotency key; X-Request-Id
        // is injected upstream (Cloudflare / Laravel Cloud reverse proxy).
        $webhookId = (string) $request->header('webhook-id', '');
        $requestId = (string) $request->header('X-Request-Id', '');

        // WHK-3: fail closed when the idempotency key is absent. The signature
        // middleware already rejects an empty webhook-id; harden the controller so
        // it does not depend on that — without an id we cannot dedup retries, so
        // queueing the email blindly risks duplicate sends on Supabase's retries.
        if ($webhookId === '') {
            return $this->error('Missing webhook-id', 400);
        }

        $user = is_array($payload['user'] ?? null) ? $payload['user'] : null;
        $emailData = is_array($payload['email_data'] ?? null) ? $payload['email_data'] : null;

        $recipientEmail = is_string($user['email'] ?? null) ? $user['email'] : null;
        $actionType = is_string($emailData['email_action_type'] ?? null) ? $emailData['email_action_type'] : null;
        $tokenHash = is_string($emailData['token_hash'] ?? null) ? $emailData['token_hash'] : null;
        $token = is_string($emailData['token'] ?? null) ? $emailData['token'] : null;
        $redirectTo = is_string($emailData['redirect_to'] ?? null) ? $emailData['redirect_to'] : null;

        if (! $recipientEmail || ! $actionType || ! $tokenHash) {
            Log::warning('supabase.email_hook.payload_invalid', [
                'has_user' => $user !== null,
                'has_email_data' => $emailData !== null,
                'action' => $actionType,
                'webhook_id' => $webhookId,
                'request_id' => $requestId,
            ]);

            return $this->error('Invalid payload', 422);
        }

        // Dedup against Supabase retries. webhook-id is the Standard Webhooks
        // idempotency key and the surrounding middleware has already proven
        // the signature is valid. TTL is sourced from the SAME config key as
        // StandardWebhookVerifier's replay-tolerance window (CFG-1/WHK-2) —
        // beyond that window, replays fail signature verification anyway, so
        // the cache entry is no longer load-bearing; the two can no longer
        // drift apart since both read one value.
        $dedupKey = 'supabase:email_hook:seen:'.$webhookId;
        $dedupTtl = (int) config('partna.cache.ttls.webhook_timestamp_tolerance', 300);
        if (! Cache::add($dedupKey, 1, now()->addSeconds($dedupTtl))) {
            Log::info('supabase.email_hook.duplicate', [
                'webhook_id' => $webhookId,
                'request_id' => $requestId,
            ]);

            return response()->json(['ok' => true, 'handled' => false, 'duplicate' => true]);
        }

        $verifyUrl = $this->buildConfirmUrl($tokenHash, $actionType, $redirectTo);
        $displayName = $this->resolveDisplayName($user);

        try {
            $mailable = $this->resolveMailable($actionType, $recipientEmail, $displayName, $verifyUrl, $token, $webhookId);
            if ($mailable === null) {
                // Unsupported action type — return success so Supabase doesn't retry
                // (it'll fall back to its built-in template). Log so we know to add support.
                Log::info('supabase.email_hook.unhandled_action', [
                    'action' => $actionType,
                    'webhook_id' => $webhookId,
                    'request_id' => $requestId,
                ]);

                // WHK-3: record unhandled outcome in the forensic trail.
                $eventService->recordUnhandled($payload, $webhookId, $actionType, $recipientEmail, $requestId);

                return response()->json(['ok' => true, 'handled' => false]);
            }

            Mail::queue($mailable);

            // WHK-3: record successful queue in the forensic trail.
            $eventService->recordQueued($payload, $webhookId, $actionType, $recipientEmail, $requestId);

            return response()->json(['ok' => true, 'handled' => true]);
        } catch (\Throwable $e) {
            // Roll back the dedup marker so Supabase's retry can re-attempt.
            // Without this, the retry sees Cache::add return false, treats it as
            // a duplicate, returns 200 OK, and Supabase permanently drops the
            // email even though it was never actually queued.
            Cache::forget($dedupKey);

            // WHK-3: record failure in the forensic trail BEFORE returning 500
            // so the row exists even if the response itself errors.
            $eventService->recordFailed($payload, $webhookId, $actionType, $recipientEmail, $e, $requestId);

            Log::error('supabase.email_hook.send_failed', [
                'action' => $actionType,
                'error' => $e->getMessage(),
                'webhook_id' => $webhookId,
                'request_id' => $requestId,
            ]);

            return $this->error('Failed to send email', 500);
        }
    }

    /**
     * Builds a link to our own /auth/confirm page. We never expose Supabase's
     * /auth/v1/verify endpoint to end users — /auth/confirm calls verifyOtp
     * client-side, then routes the user to the right destination based on the
     * email action type (recovery → reset-password form, signup → overview,
     * invite → sign-up completion).
     */
    private function buildConfirmUrl(string $tokenHash, string $actionType, ?string $redirectTo): string
    {
        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');

        // The frontend's /auth/confirm only recognises 'email_change' (not
        // 'email_change_new' or 'email_change_current') as a valid verifyOtp type.
        $frontendType = match ($actionType) {
            'email_change_new', 'email_change_current' => 'email_change',
            default => $actionType,
        };

        $params = [
            'token_hash' => $tokenHash,
            'type' => $frontendType,
        ];
        if ($redirectTo !== null && $redirectTo !== '') {
            $params['next'] = $redirectTo;
        }

        return $frontendUrl.'/auth/confirm?'.http_build_query($params);
    }

    /**
     * @param  array<string, mixed>  $user
     */
    private function resolveDisplayName(array $user): ?string
    {
        $meta = is_array($user['user_metadata'] ?? null) ? $user['user_metadata'] : [];

        foreach (['full_name', 'name', 'first_name'] as $key) {
            $value = $meta[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $first = explode(' ', trim($value))[0];

                return $first;
            }
        }

        return null;
    }

    private function resolveMailable(
        string $actionType,
        string $recipientEmail,
        ?string $displayName,
        string $verifyUrl,
        ?string $code,
        string $webhookId,
    ): ?BaseTransactionalMail {
        // Signup: 6-digit OTP flow — the verify-email screen prompts for the code.
        if ($actionType === 'signup') {
            if ($code === null || $code === '') {
                return null;
            }

            return new EmailConfirmMail($recipientEmail, $displayName, $code, $webhookId);
        }

        // Reauthentication: OTP proving it's still the account holder before a
        // sensitive change. Without this branch the action fell through to
        // Supabase's unbranded default template.
        if ($actionType === 'reauthentication') {
            if ($code === null || $code === '') {
                return null;
            }

            return new ReauthenticationMail($recipientEmail, $displayName, $code, $webhookId);
        }

        return match ($actionType) {
            // email_change_new: sent to the new address — link-click flow.
            // email_change: generic fallback Supabase may emit on some versions.
            // email_change_current: only fires when double_confirm_changes = true (disabled) — ignore.
            'email_change_new', 'email_change' => new EmailChangeMail($recipientEmail, $displayName, $verifyUrl, $webhookId),
            'email_change_current' => null,
            'recovery' => new PasswordResetMail($recipientEmail, $displayName, $verifyUrl, $webhookId),
            'magiclink' => new MagicLinkMail($recipientEmail, $displayName, $verifyUrl, $webhookId),
            'invite' => new InviteMail($recipientEmail, $displayName, $verifyUrl, $webhookId),
            default => null,
        };
    }
}
