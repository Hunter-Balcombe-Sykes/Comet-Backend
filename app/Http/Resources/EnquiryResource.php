<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class EnquiryResource extends ApiResource
{
    /**
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
            'read_at' => optional($this->read_at)->toIso8601String(),
            'is_read' => $this->read_at !== null,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
