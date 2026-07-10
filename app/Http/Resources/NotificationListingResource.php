<?php

namespace App\Http\Resources;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;

// Wire-format gate for GET /me/notifications and the staff on-behalf-of mirror.
// The underlying query uses DB::table()->get([...]) which returns stdClass rows;
// JsonResource accepts any object with public properties, so wrapping each row
// here gives us an explicit allowlist of fields that ship to the frontend.
class NotificationListingResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'user_id' => $this->user_id !== null ? (string) $this->user_id : null,
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'cta_url' => $this->cta_url,
            'primary_action_label' => $this->primary_action_label,
            'secondary_action_label' => $this->secondary_action_label,
            'secondary_action_url' => $this->secondary_action_url,
            'severity' => $this->severity,
            // OV-A: delivery escalation flag (in-app + email when true).
            'critical' => (bool) ($this->critical ?? false),
            'starts_at' => $this->formatTimestamp($this->starts_at),
            'ends_at' => $this->formatTimestamp($this->ends_at),
            'created_at' => $this->formatTimestamp($this->created_at),
            'read_at' => $this->formatTimestamp($this->read_at),
            'dismissed_at' => $this->formatTimestamp($this->dismissed_at),
        ];
    }

    // Rows from DB::table() may come back as raw strings (sqlite test driver) or
    // Carbon instances (pgsql with date casts). Preserve the original string
    // form when present so existing consumers see unchanged values; only format
    // when we actually have a Carbon to format.
    private function formatTimestamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        return (string) $value;
    }
}
