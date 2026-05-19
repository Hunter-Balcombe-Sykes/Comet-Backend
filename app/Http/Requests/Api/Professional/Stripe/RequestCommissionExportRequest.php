<?php

namespace App\Http\Requests\Api\Professional\Stripe;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates POST /stripe/exports/transactions. `role` is server-inferred from
 * the resolved professional context — never accept it from the client (prevents
 * a brand-only pro requesting affiliate-side data and vice versa).
 */
class RequestCommissionExportRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'format' => ['required', Rule::in(['csv', 'xlsx'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'type' => ['nullable', Rule::in(['all', 'charge', 'refund', 'transfer', 'reversal'])],
        ];
    }

    /**
     * Returns only the fields persisted into the audit row's `filters` JSONB —
     * shape matches what StripeRowGenerator + the existing scopedPayouts query expect.
     *
     * @return array{date_from?: string, date_to?: string, type: string}
     */
    public function onlyFilters(): array
    {
        $data = $this->validated();
        return array_filter([
            'date_from' => $data['date_from'] ?? null,
            'date_to'   => $data['date_to'] ?? null,
            'type'      => $data['type'] ?? 'all',
        ], fn ($v) => $v !== null);
    }
}
