<?php

namespace App\Http\Requests\Api\User;

use App\Http\Requests\BaseFormRequest;
use App\Services\User\ConfirmationPreferenceService;

// V2: Validates confirmation preference toggles — boolean flags for delete customer and delete media actions.
class UpdateConfirmationPreferenceRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            ConfirmationPreferenceService::ACTION_DELETE_CUSTOMER => ['sometimes', 'boolean'],
            ConfirmationPreferenceService::ACTION_DELETE_MEDIA => ['sometimes', 'boolean'],
        ];
    }
}
