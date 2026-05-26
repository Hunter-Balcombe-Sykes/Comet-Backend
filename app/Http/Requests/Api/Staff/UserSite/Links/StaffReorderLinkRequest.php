<?php

namespace App\Http\Requests\Api\Staff\UserSite\Links;

use App\Http\Requests\Api\User\Site\ReorderBlocksRequest;

// V2: Staff-facing link reorder request — inherits block reorder validation from the professional ReorderBlocksRequest.
class StaffReorderLinkRequest extends ReorderBlocksRequest
{
    // Inherits Professional Validation
}
