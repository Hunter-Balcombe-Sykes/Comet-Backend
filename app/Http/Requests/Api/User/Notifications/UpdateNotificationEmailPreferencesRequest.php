<?php

namespace App\Http\Requests\Api\User\Notifications;

use App\Http\Requests\BaseFormRequest;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Validation\Rule;

// V2: Validates notification email preference updates — array of category/enabled pairs constrained to known categories.
class UpdateNotificationEmailPreferencesRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'preferences' => ['required', 'array', 'min:1'],
            'preferences.*.category' => ['required', 'string', Rule::in(NotificationPublisher::categories())],
            'preferences.*.enabled' => ['required', 'boolean'],
        ];
    }
}
