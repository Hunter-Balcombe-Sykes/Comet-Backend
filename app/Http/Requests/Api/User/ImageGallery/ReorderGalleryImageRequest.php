<?php

namespace App\Http\Requests\Api\User\ImageGallery;

use App\Http\Requests\BaseFormRequest;

// V2: Validates gallery image reordering — requires an array of distinct UUIDs representing the new order.
class ReorderGalleryImageRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
