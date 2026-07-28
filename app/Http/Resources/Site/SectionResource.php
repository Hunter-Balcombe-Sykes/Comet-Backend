<?php

namespace App\Http\Resources\Site;

use App\Http\Resources\ApiResource;
use App\Models\Core\Site\Section;
use Illuminate\Http\Request;

/**
 * One section of a page (plan §7).
 *
 * `ruleSentence` ships alongside the raw rule because the sentence IS the UI
 * copy, not a debug rendering: the dashboard shows the user what their section
 * does in English, and re-deriving that client-side would let the two drift.
 *
 * @mixin Section
 */
class SectionResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'pageId' => (string) $this->page_id,
            'siteId' => (string) $this->site_id,
            'key' => $this->key,
            'label' => $this->label,
            'slot' => $this->slot,
            'kind' => $this->kind,
            'sortOrder' => (int) $this->sort_order,
            'rule' => (object) ($this->rule ?? []),
            'ruleSentence' => $this->ruleObject()->toSentence(),
            'mode' => $this->mode,
            'orderBy' => $this->order_by,
            'limitN' => $this->limit_n === null ? null : (int) $this->limit_n,
            'groupBy' => $this->group_by,
            'render' => $this->render,
            'density' => $this->density,
            'priceMode' => $this->price_mode,
            'minItems' => (int) $this->min_items,
            'onEmpty' => $this->on_empty,
            'staleDisplay' => $this->stale_display,
            'curation' => SectionItemResource::collection($this->whenLoaded('curation')),
            'groups' => SectionGroupResource::collection($this->whenLoaded('groups')),
            'createdAt' => $this->created_at->toIso8601String(),
            'updatedAt' => $this->updated_at->toIso8601String(),
        ];
    }
}
