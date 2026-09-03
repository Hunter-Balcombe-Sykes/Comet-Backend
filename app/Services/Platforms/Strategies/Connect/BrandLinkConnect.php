<?php

namespace App\Services\Platforms\Strategies\Connect;

use App\Catalog\CompiledCatalog;
use App\Services\Platforms\PlatformInput;
use App\Services\Platforms\Strategies\Contracts\ConnectResult;
use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;
use App\Services\Platforms\WebsiteLinkHarvester;

/**
 * Connect strategy for every derived brand surface: the input must classify
 * as a link on this brand's host(s), and the card stored is the same shape
 * the link lane writes (url, name, provider; favicon/logo enriched later).
 *
 * Handles (2026-08-18 overnight, F12): a bare token — "torvalds",
 * "@hbomberguy", "yourpub" — is expanded through the surface's
 * canonical_url_template when that template has exactly one placeholder
 * (github.com/{handle}, {handle}.substack.com, …). The owner ruled that
 * every platform which HAS a handle should accept one, not only a URL. A
 * brand without a template (booking widgets, ordering stores) still needs a
 * URL, exactly as before.
 */
final class BrandLinkConnect implements ConnectStrategy
{
    public function __construct(
        private readonly string $slug,
        private readonly string $label,
        private readonly ?string $surfaceKey = null,
    ) {}

    /**
     * The URL a connect input means for this brand — the input itself, or a
     * bare handle expanded through the surface template. PlatformConnectRequest
     * calls this BEFORE its host assertion so a handle survives validation.
     */
    public static function normaliseInput(?string $surfaceKey, string $input): string
    {
        $self = new self('', '', $surfaceKey);
        $url = trim($input);
        if ($self->looksLikeBareHandle($url)) {
            return $self->expandHandle($url) ?? $url;
        }

        return $url;
    }

    public function resolve(string $input): ConnectResult
    {
        $url = self::normaliseInput($this->surfaceKey, $input);

        // A retired brand is refused on the MANUAL lane too (2026-09-03).
        // PlacementPolicy.php:83 already routes a retired surface to
        // Verdict::Note, so a harvest can never suggest one — but nothing
        // checked lifecycle here, so a paste still connected it and attached a
        // live CTA to a brand that stopped trading. One guard on this strategy
        // covers every derived brand surface at once.
        if ($this->surfaceKey !== null
            && (CompiledCatalog::surface($this->surfaceKey)['lifecycle'] ?? null) === 'retired') {
            return ConnectResult::fail($this->label.' is no longer operating, so it can\'t be connected.');
        }

        $classified = app(WebsiteLinkHarvester::class)->classify($url);
        if ($classified === null) {
            return ConnectResult::fail('That does not look like a '.$this->label.' link.');
        }

        // Stored shape matches the card the link lane already writes for every
        // other brand of this kind, and matches this platform's DSAR allowlist
        // entry (url, name, favicon, logo, provider). favicon/logo are resolved
        // by the link-card enrichment lane, not here — a connect must not block
        // on fetching a remote icon.
        return ConnectResult::ok([
            'url' => $url,
            // classify() guarantees `label` on a non-null result, so no fallback.
            'name' => $classified['label'],
            'provider' => $this->label,
        ], $this->slug);
    }

    /**
     * No scheme, no slash, no whitespace; a leading @ is fine; dots allowed
     * (Medium/TikTok-style handles). A token whose last label reads like a
     * TLD ("site.com", "torvalds.github.io") is a HOST, never a handle — the
     * one exception is a paste of this brand's own subdomain form
     * ("yourpub.substack.com"), which is reduced to its label. Review caught
     * the first cut treating any host as a Substack handle.
     */
    private function looksLikeBareHandle(string $s): bool
    {
        $t = PlatformInput::token($s);
        if ($t === '' || str_contains($t, '/') || str_contains($t, ' ') || preg_match('~^[a-z][a-z0-9+.-]*:~i', $t)) {
            return false;
        }
        if (! preg_match('~\.[a-z]{2,}$~i', $t)) {
            return true;
        }

        return $this->isOwnSubdomainHost($t);
    }

    /** "yourpub.substack.com" for a template "https://{handle}.substack.com". */
    private function isOwnSubdomainHost(string $host): bool
    {
        $t = $this->template();
        if ($t === null || ! $this->templateIsSubdomain()) {
            return false;
        }
        $suffix = preg_replace('~^https?://\{[a-z_]+\}~i', '', $t); // ".substack.com"

        return $suffix !== null && $suffix !== '' && str_ends_with(strtolower($host), strtolower($suffix))
            && strlen($host) > strlen($suffix) && ! str_contains(substr($host, 0, -strlen($suffix)), '.');
    }

    private function expandHandle(string $s): ?string
    {
        $template = $this->template();
        if ($template === null || substr_count($template, '{') !== 1) {
            return null;
        }
        $handle = PlatformInput::token($s);
        // A pasted "yourpub.substack.com" for a subdomain template: keep the label only.
        if ($this->templateIsSubdomain() && $this->isOwnSubdomainHost($handle)) {
            $handle = preg_replace('~\..*$~', '', $handle) ?? $handle;
        }
        if ($handle === '' || ! preg_match('~^[A-Za-z0-9_.+-]{1,120}$~', $handle)) {
            return null;
        }

        return (string) preg_replace('~\{[a-z_]+\}~i', rawurlencode($handle), $template);
    }

    private function template(): ?string
    {
        if ($this->surfaceKey === null) {
            return null;
        }
        $surface = CompiledCatalog::surface($this->surfaceKey);
        $t = $surface['canonical_url_template'] ?? null;

        return is_string($t) && $t !== '' ? $t : null;
    }

    private function templateIsSubdomain(): bool
    {
        $t = $this->template();

        return $t !== null && (bool) preg_match('~^https?://\{[a-z_]+\}\.~i', $t);
    }
}
