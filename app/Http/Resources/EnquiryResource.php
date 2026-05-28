<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class EnquiryResource extends ApiResource
{
    /**
     * Transform the enquiry into an API payload.
     *
     * is_read is derived from status: any value other than 'new' means the
     * enquiry has been seen/acted on. read_at is retained for backwards
     * compatibility with existing consumers that relied on the timestamp form.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'subject' => $this->subject,
            'message' => $this->message,
            'status' => $this->status?->value,
            'is_read' => $this->status?->value !== 'new',
            'read_at' => optional($this->read_at)->toIso8601String(),
            'replied_at' => optional($this->replied_at)->toIso8601String(),
            'archived_at' => optional($this->archived_at)->toIso8601String(),
            'spam_at' => optional($this->spam_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
