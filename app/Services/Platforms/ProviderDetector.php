<?php

namespace App\Services\Platforms;

use App\Services\Platforms\Registry\PlatformCategory;
use App\Services\Platforms\Registry\PlatformRegistry;

// Maps a pasted URL to the known provider for a smart-detect category, or null
// when nothing matches (→ the custom-link fallback). Registry-driven: a category's
// candidate providers are the registered descriptors in that category that carry a
// Detection strategy, tried in registration order (= priority). Adding a provider
// is a descriptor + a ->detect(...) line in PlatformRegistryServiceProvider — no
// edit here. Detection is host-level only; the provider's connect endpoint does
// the strict path/rid validation.
class ProviderDetector
{
    public function __construct(private readonly PlatformRegistry $registry) {}

    /** The known provider for a URL within a category, or null (custom fallback). */
    public function detectFor(string $category, string $url): ?string
    {
        $cat = PlatformCategory::tryFrom($category);
        if ($cat === null) {
            return null;
        }

        $url = PlatformInput::urlish($url);

        foreach ($this->registry->all() as $descriptor) {
            $detection = $descriptor->detection();
            if ($detection !== null
                && $descriptor->getCategory() === $cat
                && $detection->matches($url)) {
                return $descriptor->key();
            }
        }

        return null;
    }

    /**
     * The detectable provider slugs for a category in registration order.
     * Detection-presence is the discriminator: fallback pseudo-platforms (e.g.
     * events-custom) have no Detection strategy and are intentionally excluded —
     * they are catch-alls that should never appear in scraper dispatch maps.
     *
     * @return list<string>
     */
    public function providersFor(string $category): array
    {
        $cat = PlatformCategory::tryFrom($category);
        if ($cat === null) {
            return [];
        }

        $slugs = [];
        foreach ($this->registry->all() as $descriptor) {
            if ($descriptor->detection() !== null && $descriptor->getCategory() === $cat) {
                $slugs[] = $descriptor->key();
            }
        }

        return $slugs;
    }
}
