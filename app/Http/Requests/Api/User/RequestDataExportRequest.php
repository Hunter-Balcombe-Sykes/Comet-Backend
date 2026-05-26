<?php

namespace App\Http\Requests\Api\User;

use App\Http\Requests\BaseFormRequest;

// V2: Currently empty body; placeholder for future filtered/partial exports
// (e.g. ?include=customers,bookings). Keeping the class lets us evolve later
// without refactoring the controller signature.
class RequestDataExportRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [];
    }
}
