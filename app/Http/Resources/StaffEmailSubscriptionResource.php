<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

// Wire-format gate for /staff/professionals/{p}/email-subscribers. Currently
// identical to UserEmailSubscriptionResource — the value is the
// architectural separation so a future staff-only field (e.g. admin_notes,
// suppression source) has an obvious home without leaking to brands.
class StaffEmailSubscriptionResource extends ApiResource
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
