<?php

namespace App\Http\Resources\Professional;

use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Commerce\CommissionExportAudit */
class CommissionExportAuditResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'format' => $this->format,
            'filters' => $this->filters,
            'payouts_total' => $this->payouts_total,
            'payouts_processed' => $this->payouts_processed,
            'chunk_size' => $this->chunk_size,
            'chunks_total' => $this->chunks_total,
            'chunks_completed' => $this->chunks_completed,
            'file_size_bytes' => $this->file_size_bytes,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toIso8601String(),
            'processing_at' => $this->processing_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
