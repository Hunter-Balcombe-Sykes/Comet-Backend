<?php

namespace App\Http\Resources;

use App\Models\Core\Notifications\EmailSubscription;
use Illuminate\Http\Request;

// Wire-format gate for /api/email-subscribers (brand-side). Explicit allowlist
// so adding a column to the EmailSubscription model never auto-exposes it to
// the brand. Mirrors StaffEmailSubscriptionResource — diverge here when a
// field should ship to brands but not staff (or vice versa).
/**
 * @mixin EmailSubscription
 */
class UserEmailSubscriptionResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'email' => $this->email,
            'full_name' => $this->full_name,
            'list_key' => $this->list_key,
            'status' => $this->status,
            'consent_source' => $this->consent_source,
            'subscribed_at' => optional($this->subscribed_at)->toIso8601String(),
            'unsubscribed_at' => optional($this->unsubscribed_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
