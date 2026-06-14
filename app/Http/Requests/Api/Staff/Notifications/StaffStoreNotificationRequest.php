<?php

namespace App\Http\Requests\Api\Staff\Notifications;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// V2: Validates staff creation of a global or targeted notification with optional email broadcast.
class StaffStoreNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorization enforced at controller via middleware
    }

    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'uuid'],
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
            // like 'commissions' / 'payouts' have canonical event sources and must
            // not be manually issuable via this endpoint.
            'category' => ['nullable', 'string', Rule::in(['policy_update', 'incident', 'feature_announcement'])],
        ];
    }
}
