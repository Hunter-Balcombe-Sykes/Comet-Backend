<?php

namespace App\Http\Requests\Api\PublicSite\Analytics;

use App\Http\Requests\BaseFormRequest;
use App\Http\Requests\Concerns\ResolvesPublicSiteSubdomain;
use Illuminate\Validation\Rule;

// Analytics v2: validates public click-tracking events. Skeleton sitepages have no
// site.blocks rows — a click is identified by its destination `url` plus optional
// structured labels (platform slug, product, owning section). `block_id` remains
// accepted for legacy block-era callers; one of block_id|url must be present.
class ClickRequest extends BaseFormRequest
{
    use ResolvesPublicSiteSubdomain;

    protected function prepareForValidation(): void
    {
        $this->mergeSubdomainFromRoute('X-Site-Subdomain');
    }

    public function rules(): array
    {
        return [
            // Legacy path — existence/ownership/trackability validated in
            // PostgresEventWriter (worker side) so the beacon never blocks on a DB read.
            'block_id' => ['nullable', 'uuid'],
            // v2 path — destination URL of the outbound anchor.
            'url' => ['required_without:block_id', 'nullable', 'string', 'max:2048', 'url:http,https'],
            // Platform slug the anchor belongs to (instagram, fresha, shopify, ...).
            'platform' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9_-]+$/i'],
            'product_id' => ['nullable', 'string', 'max:128'],
            'product_title' => ['nullable', 'string', 'max:255'],
            // Section of the sitepage the anchor lives in (shop, music, book, ...).
            'section_key' => ['nullable', 'string', 'max:64'],
            // Human label for the anchor (service name, album title, ...).
            'label' => ['nullable', 'string', 'max:255'],
            'site_id' => ['required_without:subdomain', 'uuid', Rule::exists('pgsql.site.sites', 'id')],
            'subdomain' => ['required_without:site_id', 'string', 'max:63'],
            'session_id' => ['nullable', 'uuid'],
            'visitor_id' => ['nullable', 'uuid'],
            'referrer' => ['nullable', 'string', 'max:2048'],
            'utm_source' => ['nullable', 'string', 'max:255'],
            'utm_medium' => ['nullable', 'string', 'max:255'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
        ];
    }
}
