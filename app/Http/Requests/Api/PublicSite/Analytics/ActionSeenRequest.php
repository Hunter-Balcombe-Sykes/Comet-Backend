<?php

namespace App\Http\Requests\Api\PublicSite\Analytics;

use App\Http\Requests\BaseFormRequest;
use App\Http\Requests\Concerns\ResolvesPublicSiteSubdomain;
use App\Services\PublicSite\Actions\ActionVocabulary;
use Illuminate\Validation\Rule;

// Analytics v2 — public ingest for action-exposure events fired by the
// sitepage's action-surface IntersectionObserver, one per action doorway
// entering the viewport. Mirrors ItemSeenRequest (site resolved by UUID or
// subdomain) but carries a single action_id grain instead of item_type/item_id.
class ActionSeenRequest extends BaseFormRequest
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
            // action_id is the ActionVocabulary identity — a static id or
            // '<family>:<key>'. No Rule::in() — the vocabulary includes two
            // open-ended dynamic families a fixed list can't express.
            'action_id' => ['required', 'string', 'max:180', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || ! ActionVocabulary::isValidId($value)) {
                    $fail('The action_id is not a recognised action.');
                }
            }],
            'session_id' => ['nullable', 'uuid'],
            'visitor_id' => ['nullable', 'uuid'],
            'referrer' => ['nullable', 'string', 'max:2048'],
            'utm_source' => ['nullable', 'string', 'max:255'],
            'utm_medium' => ['nullable', 'string', 'max:255'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
        ];
    }
}
