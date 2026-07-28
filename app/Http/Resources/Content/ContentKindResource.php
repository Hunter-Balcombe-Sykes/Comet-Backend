<?php

namespace App\Http\Resources\Content;

use App\Http\Resources\ApiResource;
use App\Services\Content\KindRegistry;
use Illuminate\Http\Request;

/**
 * One kind in the registry the dashboard's LibraryView builds its columns from
 * (plan §16).
 *
 * The three flags are the load-bearing part: they are what makes Reviews
 * render show/hide-only curation. The server decides, once, and the client
 * obeys — a client that re-derived "can I edit this?" from the kind name would
 * eventually offer an edit form the API refuses.
 *
 * The resource wraps the plain array {@see KindRegistry}
 * produces; there is no model, because a kind is a declaration rather than a
 * row.
 */
class ContentKindResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $kind */
        $kind = $this->resource;

        return [
            'kind' => $kind['kind'],
            'label' => $kind['label'],
            'plural' => $kind['plural'],
            'profile' => $kind['profile'],
            'facets' => $kind['facets'],
            'columns' => $kind['columns'],
            'pinnable' => $kind['pinnable'],
            'editable' => $kind['editable'],
            'orderable' => $kind['orderable'],
            'mayDelete' => $kind['mayDelete'],
            'staleDisplayDefault' => $kind['staleDisplayDefault'],
        ];
    }
}
