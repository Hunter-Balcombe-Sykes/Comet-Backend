<?php

namespace App\Http\Resources\Moderation;

use App\Http\Resources\ApiResource;

class ReportReceiptResource extends ApiResource
{
    // No wrapping — the receipt payload is returned flat (not nested under 'data').
    public static $wrap = null;

    public function toArray($request): array
    {
        return [
            'receipt_id' => $this->receiptId,
            'message'    => 'Report received. Thank you.',
        ];
    }
}
