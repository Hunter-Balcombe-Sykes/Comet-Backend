<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

class SaveSquareSelectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            // Square's employee_token — the value the booking page's
            // team_member_id param carries (TM-…).
            'employeeId' => ['required', 'string', 'max:80', 'regex:/^TM[A-Za-z0-9_-]{4,64}$/'],
        ];
    }
}
