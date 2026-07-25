<?php

namespace App\Http\Requests\Api\Staff\Notifications;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// V2: Validates staff creation of a global or targeted notification with optional email broadcast.
// OV-A: gains `audience` (all | segment | selected) + the `critical` delivery flag.
// Legacy `user_id` stays accepted (targeted-of-one) for back-compat; omitting both
// audience and user_id = global (all), the original behaviour.
class StaffStoreNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorization enforced at controller via middleware
    }

    public function rules(): array
    {
        return [
            // Reject non-existent UUIDs as 422 rather than silently creating a dangling notification (P3-09).
            // Connection prefix required: 'pgsql.core.users' so Laravel routes to the right DB connection.
            'user_id' => ['nullable', 'uuid', Rule::exists('pgsql.core.users', 'id')],

            // OV-A audience targeting.
            'audience' => ['nullable', 'array'],
            'audience.type' => ['required_with:audience', 'string', Rule::in(['all', 'segment', 'selected'])],
            'audience.segment_id' => ['required_if:audience.type,segment', 'uuid', Rule::exists('pgsql.core.user_segments', 'id')],
            'audience.user_ids' => ['required_if:audience.type,selected', 'array', 'min:1', 'max:500'],
            'audience.user_ids.*' => ['uuid', Rule::exists('pgsql.core.users', 'id')],

            // OV-A delivery escalation — read by the email dispatcher (OV-H).
            'critical' => ['nullable', 'boolean'],
            'type' => ['required', 'string', 'max:50'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'cta_url' => ['nullable', 'string', 'max:2048'],
            'primary_action_label' => ['nullable', 'string', 'max:255'],
            'secondary_action_label' => ['nullable', 'string', 'max:255'],
            'secondary_action_url' => ['nullable', 'string', 'max:2048'],
            'severity' => ['nullable', 'string', 'in:info,warning,critical'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'send_email' => ['nullable', 'boolean'],
            'email_list_key' => ['nullable', 'string', 'max:50'],
            // Whitelisted to the three staff-broadcast categories only. Categories
            // like 'platform_connection' / 'achievement' have canonical event sources
            // and must not be manually issuable via this endpoint.
            'category' => ['nullable', 'string', Rule::in(['policy_update', 'incident', 'feature_announcement'])],
        ];
    }
}
