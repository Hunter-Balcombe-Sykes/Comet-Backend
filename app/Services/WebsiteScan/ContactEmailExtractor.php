<?php

namespace App\Services\WebsiteScan;

use App\Services\Http\MetadataParser;

/**
 * Extracts a business's own contact email from an already-fetched page —
 * mailto: links first (cheapest, most reliable signal), then JSON-LD
 * email/contactPoint.email (LocalBusiness/Organization/WebSite), reusing
 * MetadataParser rather than a second JSON-LD walk. Deliberately does NOT
 * attempt footer text-pattern deobfuscation ("email [at] domain") — poor
 * cost/benefit, and JS-obfuscated variants are unreachable anyway (this
 * pipeline does one plain fetch, no headless render).
 */
class ContactEmailExtractor
{
    /** Checked in order — the first JSON-LD block of any of these types that carries an email wins. */
    private const JSON_LD_TYPES = ['LocalBusiness', 'Organization', 'WebSite'];

    /** Non-contact mailbox prefixes a mailto: link can carry — never useful as a business's own contact email. */
    private const IGNORED_LOCAL_PARTS = ['noreply', 'no-reply', 'donotreply', 'do-not-reply', 'mailer-daemon', 'postmaster', 'webmaster'];

    public function __construct(private MetadataParser $parser) {}

    public function extract(string $html, string $baseUrl): ?string
    {
        return $this->fromMailto($html) ?? $this->fromJsonLd($html, $baseUrl);
    }

    private function fromMailto(string $html): ?string
    {
        if (trim($html) === '') {
            return null;
        }

        $doc = new \DOMDocument;
        $prev = libxml_use_internal_errors(true);
        // LIBXML_NONET (#FU-1): $html is a third party's scraped page — this
        // blocks any network access libxml would make while parsing (external
        // entity/DTD/stylesheet), same flag and reasoning as MetadataParser,
        // AboutProseExtractor and WebsiteLinkHarvester::extractLinks().
        $doc->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        foreach ($doc->getElementsByTagName('a') as $a) {
            $href = trim((string) $a->getAttribute('href'));
            if (! str_starts_with(strtolower($href), 'mailto:')) {
                continue;
            }
            // Strip the scheme + any ?subject=/?cc= query the mailto: link carries.
            $address = trim(explode('?', substr($href, 7))[0]);
            $email = $this->validate($address);
            if ($email !== null) {
                return $email;
            }
        }

        return null;
    }

    private function fromJsonLd(string $html, string $baseUrl): ?string
    {
        $parsed = $this->parser->parse($html, $baseUrl);

        foreach (self::JSON_LD_TYPES as $type) {
            $node = $parsed->jsonLdOfType($type);
            if ($node === null) {
                continue;
            }

            $direct = $this->validate(is_string($node['email'] ?? null) ? $node['email'] : null);
            if ($direct !== null) {
                return $direct;
            }

            $email = $this->fromContactPoint($node['contactPoint'] ?? null);
            if ($email !== null) {
                return $email;
            }
        }

        return null;
    }

    /** schema.org contactPoint is either a single object or a list of them. */
    private function fromContactPoint(mixed $contactPoint): ?string
    {
        if (! is_array($contactPoint)) {
            return null;
        }
        $points = array_is_list($contactPoint) ? $contactPoint : [$contactPoint];

        foreach ($points as $point) {
            if (is_array($point)) {
                $email = $this->validate(is_string($point['email'] ?? null) ? $point['email'] : null);
                if ($email !== null) {
                    return $email;
                }
            }
        }

        return null;
    }

    /** Trimmed, lowercased, filter_var-validated address; null for blank/malformed/ignored-prefix input. */
    private function validate(?string $address): ?string
    {
        if ($address === null) {
            return null;
        }
        $address = strtolower(trim(rawurldecode($address)));
        if ($address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        $localPart = explode('@', $address)[0];
        if (in_array($localPart, self::IGNORED_LOCAL_PARTS, true)) {
            return null;
        }

        return $address;
    }
}
