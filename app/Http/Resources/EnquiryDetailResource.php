<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class EnquiryDetailResource extends EnquiryResource
{
    /**
     * Full enquiry detail payload — extends EnquiryResource with a mailto_url,
     * the eager-loaded customer, and a pre-attached history collection.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'mailto_url' => sprintf(
                'mailto:%s?subject=%s',
                rawurlencode((string) $this->email),
                rawurlencode('Re: '.(string) $this->subject),
            ),
            'customer' => $this->whenLoaded('customer', fn () => new CustomerResource($this->customer)),
            'history' => $this->getHistoryPayload(),
        ]);
    }

    /**
     * Maps historyForDetailView (set by the controller before serialisation)
     * to a compact array suitable for the inbox thread list.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getHistoryPayload(): array
    {
        $history = $this->resource->historyForDetailView ?? collect();

        return $history->map(fn ($e) => [
            'id' => (string) $e->id,
            'subject' => $e->subject,
            'created_at' => optional($e->created_at)->toIso8601String(),
            'status' => $e->status?->value,
        ])->all();
    }
}
