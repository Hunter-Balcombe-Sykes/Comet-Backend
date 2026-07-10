<?php

namespace App\Http\Resources\Staff;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * OV-D: staff-facing feedback row. Unlike the owner-facing FeedbackResource,
 * this exposes every triage field (reply_email, request_id, tags,
 * internal_notes, ip_hash) plus a nested user summary — staff need full
 * context across ALL users' submissions, not just their own. `ip_hash` is a
 * one-way SHA256 (never raw IP), kept for abuse-correlation triage per the
 * original migration's documented intent.
 *
 * `user` is null when the submitting account was hard-deleted (user_id is
 * ON DELETE SET NULL — see Feedback model docblock) or soft-deleted (default
 * SoftDeletes scope excludes it from the eager-loaded relation).
 */
class StaffFeedbackResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'user' => $this->when($this->relationLoaded('user'), fn () => $this->user === null ? null : [
                'id' => (string) $this->user->id,
                'handle' => $this->user->handle,
                'display_name' => $this->user->display_name,
                'email' => $this->user->primary_email,
            ]),
            'kind' => $this->kind,
            'severity' => $this->severity,
            'type' => $this->type,
            'area' => $this->area,
            'target' => $this->target,
            'message' => $this->message,
            'status' => $this->status,
            'page_url' => $this->page_url,
            'user_agent' => $this->user_agent,
            'viewport' => $this->viewport,
            'app_version' => $this->app_version,
            'request_id' => $this->request_id,
            'reply_email' => $this->reply_email,
            'source' => $this->source,
            'tags' => $this->tags ?? [],
            'internal_notes' => $this->internal_notes ?? [],
            'ip_hash' => $this->ip_hash,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
