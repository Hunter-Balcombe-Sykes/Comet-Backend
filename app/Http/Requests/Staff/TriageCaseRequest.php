<?php

namespace App\Http\Requests\Staff;

use App\DTOs\Moderation\TriageDto;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a staff triage action on a moderation case.
 * Both fields are optional — omitting priority keeps the current value.
 */
class TriageCaseRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'priority' => ['nullable', 'integer', 'min:1', 'max:10'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function toDto(): TriageDto
    {
        return new TriageDto(
            priority: $this->integer('priority') ?: null,
            notes: $this->string('notes')->toString() ?: null,
        );
    }
}
