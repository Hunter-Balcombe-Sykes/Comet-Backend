<?php

namespace App\Http\Requests\Api\PublicSite\Analytics;

use App\Http\Requests\BaseFormRequest;
use App\Http\Requests\Concerns\ResolvesPublicSiteSubdomain;
use Illuminate\Validation\Rule;

// Per-page dwell ingest — the storefront reports each page panel's CUMULATIVE
// visible-time when the visitor leaves it (panel change / tab hide). The writer
// GREATEST-merges the value onto the matching section impression row, so
// retries and out-of-order delivery are harmless (ping's idempotency pattern).
//
// Unlike section-seen, an identifier is REQUIRED (visitor_id or session_id):
// without one the writer cannot locate the impression row to annotate, so the
// request would only ever be dropped — fail fast with a 422 instead.
class SectionDwellRequest extends BaseFormRequest
{
    use ResolvesPublicSiteSubdomain;

    protected function prepareForValidation(): void
    {
        $this->mergeSubdomainFromRoute('X-Site-Subdomain');
    }

    public function rules(): array
    {
        return [
            'site_id' => ['required_without:subdomain', 'uuid', Rule::exists('pgsql.site.sites', 'id')],
            'subdomain' => ['required_without:site_id', 'string', 'max:63'],
            'section_key' => ['required', 'string', 'max:64'],
            // Cumulative ms this section has been the visible panel. Floor 1s (sub-second
            // glances are scroll-through noise, not dwell); cap 10min (bounds both abuse
            // and left-open-tab inflation — the client stops counting when hidden anyway).
            'duration_ms' => ['required', 'integer', 'min:1000', 'max:600000'],
            'visitor_id' => ['required_without:session_id', 'nullable', 'uuid'],
            'session_id' => ['required_without:visitor_id', 'nullable', 'uuid'],
        ];
    }
}
