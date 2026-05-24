<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Api\ApiController;
use App\Mail\Auth\EmailConfirmMail;
use App\Mail\Auth\InviteMail;
use App\Mail\Auth\MagicLinkMail;
use App\Mail\Auth\PasswordResetMail;
use App\Mail\BaseTransactionalMail;
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
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->json()->all();

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
            ]);

            return $this->error('Invalid payload', 422);
        }

        // Dedup against Supabase retries. webhook-id is the Standard Webhooks
        // idempotency key and the surrounding middleware has already proven
        // the signature is valid. TTL matches the signature verifier's
        // TIMESTAMP_TOLERANCE (300s) — beyond that, replays fail signature
        // verification anyway, so the cache entry is no longer load-bearing.
        $webhookId = (string) $request->header('webhook-id', '');
        if ($webhookId !== '') {
            $dedupKey = 'supabase:email_hook:seen:'.$webhookId;
            if (! Cache::add($dedupKey, 1, now()->addSeconds(300))) {
                Log::info('supabase.email_hook.duplicate', ['webhook_id' => $webhookId]);

                return response()->json(['ok' => true, 'handled' => false, 'duplicate' => true]);
            }
        }

        $verifyUrl = $this->buildConfirmUrl($tokenHash, $actionType, $redirectTo);
        $displayName = $this->resolveDisplayName($user);

        try {
            $mailable = $this->resolveMailable($actionType, $recipientEmail, $displayName, $verifyUrl, $token);
            if ($mailable === null) {
                // Unsupported action type — return success so Supabase doesn't retry
                // (it'll fall back to its built-in template). Log so we know to add support.
                Log::info('supabase.email_hook.unhandled_action', ['action' => $actionType]);

                return response()->json(['ok' => true, 'handled' => false]);
            }

            Mail::queue($mailable);

            return response()->json(['ok' => true, 'handled' => true]);
        } catch (\Throwable $e) {
            Log::error('supabase.email_hook.send_failed', [
                'action' => $actionType,
                'error' => $e->getMessage(),
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

        $params = [
            'token_hash' => $tokenHash,
            'type' => $actionType,
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
    ): ?BaseTransactionalMail {
        // Email-confirmation actions (signup, email change) use the 6-digit
        // OTP from email_data.token. The frontend's verify step prompts for
        // exactly this code, so a click-link would only confuse — the email
        // is always opened side-by-side with the form asking for the digits.
        $isConfirmAction = in_array(
            $actionType,
            ['signup', 'email_change', 'email_change_current', 'email_change_new'],
            true
        );

        if ($isConfirmAction) {
            if ($code === null || $code === '') {
                return null;
            }

            return new EmailConfirmMail($recipientEmail, $displayName, $code);
        }

        return match ($actionType) {
            'recovery' => new PasswordResetMail($recipientEmail, $displayName, $verifyUrl),
            'magiclink' => new MagicLinkMail($recipientEmail, $displayName, $verifyUrl),
            'invite' => new InviteMail($recipientEmail, $displayName, $verifyUrl),
            default => null,
        };
    }
}
