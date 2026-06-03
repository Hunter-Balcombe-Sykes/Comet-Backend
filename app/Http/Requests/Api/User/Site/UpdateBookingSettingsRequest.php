<?php

namespace App\Http\Requests\Api\User\Site;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

// Validates the dedicated booking-settings endpoint — booking_mode and
// manual_booking_url. Scoped separately from UpdateSiteRequest so the
// frontend can update booking settings without sending the full site payload.
class UpdateBookingSettingsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'booking_mode'       => ['required', 'string', Rule::in(['manual'])],
            'manual_booking_url' => ['nullable', 'url', 'max:2048'],
        ];
    }
}
