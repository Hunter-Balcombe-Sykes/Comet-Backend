<?php

namespace App\Http\Requests\Api\User\ContentLibrary;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * A human's ruling on a possible duplicate (plan §5).
 *
 * Two verdicts, no third: `same` merges, `different` cuts. There is
 * deliberately no "not sure" — that is what leaving the card alone means, and
 * a stored maybe would only be a card the queue shows forever.
 */
class RuleIdentityCandidateRequest extends BaseFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'verdict' => ['required', Rule::in(['same', 'different'])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'verdict.in' => 'A ruling is either "same" or "different".',
        ];
    }
}
