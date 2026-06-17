<?php

namespace App\Services\Platforms;

// Maps a pasted URL to the known provider for a smart-detect category, or null
// when nothing matches (→ the custom-link fallback). Detection is host-level
// only — the provider's own connect endpoint does the strict path/rid
// validation. A registry: new providers slot in by adding a host matcher and a
// CATEGORY_PROVIDERS entry, no other code changes.
class ProviderDetector
{
    // Providers each category can detect, in priority order.
    private const CATEGORY_PROVIDERS = [
        'booking' => ['fresha', 'square'],
        'reservations' => ['opentable', 'resdiary', 'nowbookit'],
        // No ordering platforms integrated yet — every link is a custom card.
        'online-ordering' => [],
    ];

    public function __construct(
        private readonly OpenTableService $openTable,
        private readonly ResDiaryService $resDiary,
        private readonly NowBookitService $nowBookit,
    ) {}

    /** The known provider for a URL within a category, or null (custom fallback). */
    public function detectFor(string $category, string $url): ?string
    {
        $url = PlatformInput::urlish($url);

        foreach (self::CATEGORY_PROVIDERS[$category] ?? [] as $provider) {
            if ($this->matches($provider, $url)) {
                return $provider;
            }
        }

        return null;
    }

    private function matches(string $provider, string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return match ($provider) {
            // Mirrors the host accepted by ConnectFreshaRequest (www.fresha.com).
            'fresha' => (bool) preg_match('~(^|\.)fresha\.com$~', $host),
            // Mirrors ConnectSquareRequest: book.squareup.com, squareup.com, *.square.site.
            'square' => (bool) preg_match('~(^|\.)(squareup\.com|square\.site)$~', $host),
            'opentable' => $this->openTable->isOpenTableUrl($url),
            'resdiary' => $this->resDiary->isResDiaryUrl($url),
            'nowbookit' => $this->nowBookit->isNowBookitUrl($url),
            default => false,
        };
    }
}
