<?php

namespace App\Http\Requests\Platforms;

use App\Catalog\LegacyPlatformMap;
use App\Http\Requests\Platforms\Concerns\ResolvesConnectRules;
use App\Services\Platforms\Registry\DerivedDescriptorFactory;
use App\Services\Platforms\Registry\PlatformRouteShape;
use App\Services\Platforms\Strategies\Connect\BrandLinkConnect;
use App\Services\Platforms\WebsiteLinkHarvester;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

// The single connect request for every reducible platform. Its field, rules,
// custom 422 messages, and pre-validation normalisation all come from the
// platform descriptor resolved off the route's 'platform' default. Adding a
// platform = one ->connectInput(...) line in PlatformRegistryServiceProvider, not
// a new request class. GoogleBusiness (multi-field) keeps ConnectGoogleBusinessRequest.
class PlatformConnectRequest extends FormRequest
{
    use ResolvesConnectRules;

    /**
     * Brand-shaped platforms additionally assert the URL belongs to the brand in
     * the path. This lives here rather than in a subclass because
     * GenericPlatformController::connect() type-hints THIS class concretely —
     * Laravel would never inject a subclass into it.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->assertUrlBelongsToBrand($validator)];
    }

    /**
     * The point of per-brand routes is that /platforms/menulog/connect means
     * Menulog. Without this, a DoorDash URL posted there would be classified
     * freely by LinkRouter and written as DoorDash — a family classifier wearing
     * a brand's URL, which is the thing this shape exists to end.
     *
     * Only PlatformRouteShape::Brand is checked. Every hand-written platform
     * keeps its existing behaviour untouched: their connect strategies already
     * parse and reject on their own grammar, and several deliberately accept
     * inputs classify() knows nothing about (handles, ids, search terms).
     */
    private function assertUrlBelongsToBrand(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $descriptor = $this->connectDescriptor();
        if ($descriptor->routeShape() !== PlatformRouteShape::Brand) {
            return;
        }

        $slug = $descriptor->key();
        $url = $this->input($descriptor->connectField());
        if (! is_string($url)) {
            return;
        }

        // A bare handle ("torvalds", "yourpub") is expanded through the
        // surface's canonical template before the host check (F12). Only the
        // CHECK uses the expansion here: validated() snapshots the input
        // before after() runs, so a merge() would not reach the controller —
        // BrandLinkConnect::resolve() re-runs normaliseInput() on the raw
        // value and stores the expanded URL itself.
        $url = BrandLinkConnect::normaliseInput($descriptor->getSurfaceKey(), $url);

        $classified = app(WebsiteLinkHarvester::class)->classify($url);

        if ($classified === null) {
            $validator->errors()->add(
                $descriptor->connectField(),
                'That does not look like a '.$descriptor->getLabel().' link.'
            );

            return;
        }

        // classify() returns a legacy slug for a registered brand and a full
        // surface key for a catalog-only one; legacyFor() reduces both to the
        // same comparable form (and passes a bare slug through unchanged).
        $actual = LegacyPlatformMap::legacyFor($classified['platform']);

        if ($actual === $slug) {
            return;
        }

        // Two brands are matched by inline regexes inside classify() that yield
        // the legacy pseudo-slug 'events-custom' rather than the brand's own key.
        // Without this they would 422 on a perfectly valid URL.
        if (in_array($actual, DerivedDescriptorFactory::CLASSIFIER_ALIASES[$slug] ?? [], true)) {
            return;
        }

        $validator->errors()->add(
            $descriptor->connectField(),
            'That looks like a '.$classified['label'].' link, not a '.$descriptor->getLabel().' link.'
        );
    }
}
