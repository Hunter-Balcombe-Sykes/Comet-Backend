<?php

namespace App\Http\Requests\Api\User\ContentLibrary;

use App\Http\Requests\BaseFormRequest;
use App\Services\Content\FacetRegistry;
use Closure;

/**
 * Write one manual override (plan §6).
 *
 * `value` uses `present`, not `required`: an explicit NULL is the user
 * CLEARING the field and must beat every source that would otherwise fill it.
 * `required` would reject exactly that case, and `sometimes` would silently
 * turn a clear into a no-op.
 *
 * The (facet, column) pair is checked against {@see FacetRegistry} because an
 * override is honoured absolutely and forever: an unvalidated pair is a
 * durable row that nothing reads, sitting in the table looking authoritative.
 */
class UpsertManualOverrideRequest extends BaseFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'facet' => ['required', 'string', 'max:40'],
            'column' => ['required', 'string', 'max:40', $this->knownColumnRule()],
            'value' => ['present'],
        ];
    }

    private function knownColumnRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $facet = (string) $this->input('facet');

            if (! FacetRegistry::allows($facet, (string) $value)) {
                $fail('"'.$facet.'.'.$value.'" is not a field you can edit by hand.');
            }
        };
    }
}
