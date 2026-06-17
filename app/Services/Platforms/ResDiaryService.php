<?php

namespace App\Services\Platforms;

// ResDiary reservations — connect by restaurant link, render the keyless
// ResDiary booking widget in an iframe (live availability + booking, served to
// the VISITOR's browser so no server-side scrape is needed). Mirrors the
// OpenTable contract: a URL in, an embedUrl out.
//
// Two reliable inputs: (1) the widget URL the user exports from ResDiary's
// Widget Configurator (booking.resdiary.com/widget/...) — embedded verbatim;
// (2) the restaurant's ResDiary microsite/booking page — we build the standard
// widget URL from the microsite name.
class ResDiaryService
{
    /** True for any resdiary.com host (booking.resdiary.com, <name>.resdiary.com, ...). */
    public function isResDiaryUrl(string $url): bool
    {
        $host = strtolower((string) parse_url(PlatformInput::urlish($url), PHP_URL_HOST));

        return (bool) preg_match('~(^|\.)resdiary\.com$~', $host);
    }

    /**
     * The restaurant's ResDiary microsite name — read from a /widget/<type>/<name>
     * path, a /restaurant|/r/<name> path, or a <name>.resdiary.com subdomain.
     * Null when none is present.
     */
    public function parseMicrosite(string $url): ?string
    {
        $url = PlatformInput::urlish($url);

        // Widget URL carries the name: /widget/Standard/<Name>[/...].
        if (preg_match('~resdiary\.com/widget/[^/]+/([^/?#]+)~i', $url, $m)) {
            return $this->clean($m[1]);
        }
        // Microsite / booking page: /restaurant/<name> or /r/<name>.
        if (preg_match('~resdiary\.com/(?:restaurant|r)/([^/?#]+)~i', $url, $m)) {
            return $this->clean($m[1]);
        }
        // <name>.resdiary.com subdomain (excluding the generic hosts).
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (preg_match('~^([a-z0-9-]+)\.resdiary\.com$~', $host, $m)
            && ! in_array($m[1], ['www', 'booking', 'book', 'secure'], true)) {
            return $this->clean($m[1]);
        }

        return null;
    }

    /** Best-effort display name from the microsite slug; null when none. */
    public function nameFromUrl(string $url): ?string
    {
        $micro = $this->parseMicrosite($url);
        if ($micro === null) {
            return null;
        }
        $name = ucwords(str_replace(['-', '_'], ' ', $micro));

        return $name !== '' ? $name : null;
    }

    /**
     * Keyless ResDiary booking-widget URL for an iframe. A pasted /widget/ URL
     * (the configurator's own iframe src) is used verbatim; otherwise the
     * standard widget URL is built from the microsite name. Null when neither
     * applies (caller nudges the user for a proper ResDiary link).
     */
    public function embedUrl(string $url, ?string $microsite = null): ?string
    {
        $url = PlatformInput::urlish($url);

        // Already a widget URL → embed exactly what the configurator gave them.
        if (preg_match('~resdiary\.com/widget/~i', $url)) {
            return $url;
        }

        $microsite ??= $this->parseMicrosite($url);
        if ($microsite === null) {
            return null;
        }

        return 'https://booking.resdiary.com/widget/Standard/'.rawurlencode($microsite);
    }

    private function clean(string $value): ?string
    {
        $v = trim(rawurldecode($value));

        return $v !== '' ? $v : null;
    }
}
