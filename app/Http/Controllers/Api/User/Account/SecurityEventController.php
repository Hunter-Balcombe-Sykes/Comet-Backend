<?php

namespace App\Http\Controllers\Api\User\Account;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Mail\Security\PasswordChangedMail;
use App\Mail\Security\TwoFactorEnabledMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * Notice-only pings from the dashboard for account mutations that happen
 * client-side against Supabase (password change, MFA enrolment) — the backend
 * never sees those, so the client tells it afterwards and we email the notice.
 *
 * Trust model: authenticated, self-scoped (you can only trigger mail about
 * your own account), sends notices only — never a security decision. A short
 * Cache::add cooldown stops replay spam from a stolen token being used to
 * flood the inbox.
 */
class SecurityEventController extends ApiController
{
    use ResolveCurrentUser;

    private const EVENTS = [
        'password_changed' => PasswordChangedMail::class,
        'two_factor_enabled' => TwoFactorEnabledMail::class,
    ];

    private const COOLDOWN_SECONDS = 300;

    public function store(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $event = trim((string) $request->input('event', ''));
        if (! array_key_exists($event, self::EVENTS)) {
            return $this->error('Unknown security event.', 422);
        }

        // Same event inside the cooldown → acknowledged, not re-sent.
        if (! Cache::add("security-event-mail:{$user->id}:{$event}", 1, self::COOLDOWN_SECONDS)) {
            return $this->success(['ok' => true, 'deduped' => true]);
        }

        $email = (string) ($user->primary_email ?? '');
        if ($email !== '') {
            $class = self::EVENTS[$event];
            Mail::to($email)->queue(new $class($email, $user->display_name));
        }

        return $this->success(['ok' => true]);
    }
}
