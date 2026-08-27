<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Notifications\EmailSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// V2: Processes token-based email unsubscribe requests from email footer links.
class PublicEmailUnsubscribeController extends ApiController
{
    public function unsubscribe(Request $request, string $token): JsonResponse
    {
        // Short-circuit empty / obviously-bogus tokens before hitting the DB.
        // Real tokens are Str::random(48) — the EmailSubscription token convention.
        if (strlen($token) < 10) {
            return $this->error('Invalid or expired unsubscribe link.', 404);
        }

        $sub = EmailSubscription::query()
            ->where('unsubscribe_token', $token)
            ->first();

        if (! $sub) {
            return $this->error('Invalid or expired unsubscribe link.', 404);
        }

        if ($sub->status !== 'unsubscribed') {
            $sub->markUnsubscribed();
            $sub->save();
        }

        return $this->success(['ok' => true, 'unsubscribed' => true]);
    }
}
