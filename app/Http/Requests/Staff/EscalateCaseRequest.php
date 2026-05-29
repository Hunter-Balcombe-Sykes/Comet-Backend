<?php

namespace App\Http\Requests\Staff;

use App\DTOs\Moderation\EscalationDto;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a staff escalation action on a moderation case.
 * Notes must be detailed (min 20 chars) because escalations to external
 * authorities require documented justification.
 */
class EscalateCaseRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'escalation_target' => ['required', 'string', 'in:law_enforcement,esafety'],
            'notes'             => ['required', 'string', 'min:20', 'max:4000'],
        ];
    }

    public function toDto(): EscalationDto
    {
        return new EscalationDto(
            target: $this->string('escalation_target')->toString(),
            notes:  $this->string('notes')->toString(),
        );
    }
}
