<?php

namespace App\Http\Resources\Content;

use App\Http\Resources\ApiResource;
use App\Models\Content\IdentityCandidate;
use App\Models\Content\Item;
use Illuminate\Http\Request;

/**
 * One card in the "possible duplicates" queue (plan §5).
 *
 * Both sides carry their headline and kind because the user is being asked a
 * question about two THINGS, not two ids — a card that shows only UUIDs is a
 * card nobody can answer.
 *
 * @mixin IdentityCandidate
 */
class IdentityCandidateResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'score' => (int) $this->score,
            'evidence' => (object) ($this->evidence ?? []),
            'dismissedAt' => $this->dismissed_at?->toIso8601String(),
            'createdAt' => $this->created_at->toIso8601String(),
            'left' => $this->side($this->whenLoaded('leftItem'), (string) $this->left_item_id),
            'right' => $this->side($this->whenLoaded('rightItem'), (string) $this->right_item_id),
        ];
    }

    /** @return array<string, mixed> */
    private function side(mixed $item, string $fallbackId): array
    {
        if (! $item instanceof Item) {
            return ['itemId' => $fallbackId, 'kind' => null, 'headline' => null, 'firstSeenAt' => null];
        }

        return [
            'itemId' => (string) $item->id,
            'kind' => $item->kind,
            'headline' => $item->headline_cache,
            'firstSeenAt' => $item->first_seen_at->toIso8601String(),
        ];
    }
}
