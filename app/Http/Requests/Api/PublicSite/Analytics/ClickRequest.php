<?php

namespace App\Http\Requests\Api\PublicSite\Analytics;

use App\Http\Requests\BaseFormRequest;
use App\Http\Requests\Concerns\ResolvesPublicSiteSubdomain;
use App\Services\Analytics\AnalyticsEventSanitizer;
use Closure;
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

        // Normalise BEFORE the rules run, so the ONE normalised spelling is what
        // the dedup target hashes (AnalyticsController::click) and what the row
        // stores. Normalising later would leave 'tel:+61 400 000 000' and
        // 'tel:0400000000' minting separate dedup keys for the same tap.
        // Unrecognised values are left raw so the rule below reports the value
        // the visitor's browser actually sent.
        $url = $this->input('url');
        if (is_string($url)) {
            $this->merge(['url' => AnalyticsEventSanitizer::clickUrl($url) ?? $url]);
        }
    }

    public function rules(): array
    {
        return [
            // Legacy path — existence/ownership/trackability validated in
            // PostgresEventWriter (worker side) so the beacon never blocks on a DB read.
            'block_id' => ['nullable', 'uuid'],
            // v2 path — destination URL of the outbound anchor. `url:http,https`
            // (the rule until 2026-09-01) rejected mailto: and tel:, which the
            // tracker has always sent — both parse to origin "null", so every
            // contact tap looked outbound, fired, and 422'd. A tel: tap IS the
            // conversion, so the schemes are accepted and AnalyticsEventSanitizer
            // is the single definition of an acceptable destination.
            'url' => [
                'required_without:block_id', 'nullable', 'string', 'max:2048',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || AnalyticsEventSanitizer::clickUrl($value) === null) {
                        $fail('The :attribute field must be an http, https, mailto or tel destination.');
                    }
                },
            ],
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
