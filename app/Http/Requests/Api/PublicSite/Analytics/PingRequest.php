<?php

namespace App\Http\Requests\Api\PublicSite\Analytics;

use App\Http\Requests\BaseFormRequest;
use App\Http\Requests\Concerns\ResolvesPublicSiteSubdomain;
use Illuminate\Validation\Rule;

// Analytics v2 — session heartbeat from the sitepage tracker. The client mints a
// session UUID and reports its CUMULATIVE visible-time every ~25s; the writer
// upserts analytics.site_sessions with GREATEST() so retries and out-of-order
// delivery are harmless. last_seen_at from these pings powers "live now".
class PingRequest extends BaseFormRequest
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
            'session_id' => ['required', 'uuid'],
            'visitor_id' => ['nullable', 'uuid'],
            // Cumulative seconds the page has been visible this session. 24h cap
            // mirrors the DB CHECK so a stuck tab can't distort averages.
            'seconds' => ['required', 'integer', 'min:0', 'max:86400'],
            'referrer' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
