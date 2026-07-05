<?php

namespace App\Http\Requests\Platforms;

use App\Services\Platforms\Registry\PlatformRegistry;
use Illuminate\Foundation\Http\FormRequest;

// The single highlights-save request for every picker platform. Field name +
// rules come from the descriptor's HighlightsStrategy resolved off the route's
// 'platform' default (mirrors PlatformConnectRequest). 404 fail-closed when the
// platform is unknown or has no highlights strategy.
class PlatformHighlightsRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $platform = $this->route('platform');
        abort_if(! is_string($platform) || $platform === '', 404);

        $strategy = app(PlatformRegistry::class)->get($platform)?->highlightsStrategy();
        abort_if($strategy === null, 404);

        return $strategy->rules();
    }
}
