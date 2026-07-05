<?php

namespace App\Http\Requests\Api\User\Profile;

use App\Http\Requests\BaseFormRequest;
use App\Services\Profile\SectorTaxonomy;
use Illuminate\Validation\Rule;

// Validates a manual sector pick. `sector` is nullable (clearing the field is
// allowed) but, when present, must be one of the curated SectorTaxonomy slugs —
// the closure rule rejects anything the picker couldn't have produced.
class UpdateSectorRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        // Normalise "" → null so an empty submission clears the sector rather
        // than failing the slug check.
        $sector = $this->input('sector');
        if (is_string($sector) && trim($sector) === '') {
            $this->merge(['sector' => null]);
        }
    }

    public function rules(): array
    {
        return [
            'sector' => [
                'present',
                'nullable',
                'string',
                Rule::in($this->validSectorSlugs()),
            ],
        ];
    }

    /** @return list<string> */
    private function validSectorSlugs(): array
    {
        // Rule::in over the taxonomy keeps the allowlist in one place
        // (SectorTaxonomy) rather than duplicating slugs into the request.
        return array_merge(
            ...array_map(
                fn (array $group) => array_column($group['options'], 'slug'),
                SectorTaxonomy::all(),
            ),
        );
    }
}
