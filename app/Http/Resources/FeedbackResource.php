<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * Owner-facing view of a Feedback row. Internal triage fields (internal_notes,
 * tags, ip_hash, request_id, reply_email, user_id) are deliberately omitted —
 * they belong to the staff-only StaffFeedbackResource
 * (app/Http/Resources/Staff/StaffFeedbackResource.php). `type`/`area`/`target`
 * ARE included — the submitting user supplied them, so echoing them back is
 * not a leak.
 */
class FeedbackResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'kind' => $this->kind,
            'severity' => $this->severity,
            'type' => $this->type,
            'area' => $this->area,
            'target' => $this->target,
            'message' => $this->message,
            'status' => $this->status,
            'page_url' => $this->page_url,
            'app_version' => $this->app_version,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
