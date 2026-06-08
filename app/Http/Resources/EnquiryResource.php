<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class EnquiryResource extends ApiResource
{
    /**
     * Transform the enquiry into an API payload.
     *
     * is_read is derived from status: any value other than 'new' means the
     * enquiry has been seen/acted on. When status is null (e.g. a row created
     * without the DB default) we fall back to read_at presence rather than
     * defaulting to read. read_at is also exposed for consumers that relied on
     * the timestamp form.
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
            'is_read' => $this->status !== null ? $this->status->value !== 'new' : $this->read_at !== null,
            'read_at' => optional($this->read_at)->toIso8601String(),
            'replied_at' => optional($this->replied_at)->toIso8601String(),
            'archived_at' => optional($this->archived_at)->toIso8601String(),
            'spam_at' => optional($this->spam_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
