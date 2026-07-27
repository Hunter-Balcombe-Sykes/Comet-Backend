<?php

namespace App\Routing;

/**
 * Vendored Public Suffix List matcher (plan §2) — the registrable-domain
 * primitive under IriCanonicalizer. Replaces every spoofable host regex with
 * the real eTLD+1 boundary: `opentable.evil.com` registers as `evil.com`,
 * never as anything OpenTable-shaped.
 *
 * Standard PSL algorithm: exception rules beat wildcards, longest match wins,
 * unknown TLDs fall to the implicit `*` rule. Rules are normalised to ASCII
 * (punycode) at parse time; callers must pass ASCII hosts (the canonicaliser
 * runs UTS-46 IDN conversion before consulting this). The parsed set is
 * process-memoised; the P2 rulepack embeds a compiled snapshot so the router
 * never re-parses in production.
 */
class PublicSuffixList
{
    private static ?self $instance = null;

    /** @var array<string, true> exact rules */
    private array $rules = [];

    /** @var array<string, true> wildcard parents ("*.ck" stored as "ck") */
    private array $wildcards = [];

    /** @var array<string, true> exception rules ("!www.ck" stored as "www.ck") */
    private array $exceptions = [];

    public static function instance(): self
    {
        return self::$instance ??= self::fromFile(base_path('resources/psl/public_suffix_list.dat'));
    }

    public static function fromFile(string $path): self
    {
        $psl = new self;
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Cannot read PSL at {$path}");
        }
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '//')) {
                continue;
            }
            $exception = str_starts_with($line, '!');
            $wildcard = str_starts_with($line, '*.');
            $rule = $exception ? substr($line, 1) : ($wildcard ? substr($line, 2) : $line);
            $ascii = self::toAscii($rule);
            if ($ascii === null) {
                continue;
            }
            if ($exception) {
                $psl->exceptions[$ascii] = true;
            } elseif ($wildcard) {
                $psl->wildcards[$ascii] = true;
            } else {
                $psl->rules[$ascii] = true;
            }
        }
        fclose($handle);

        return $psl;
    }

    /**
     * The public suffix of an ASCII host ("foo.com.au" → "com.au";
     * "foo.unknowntld" → "unknowntld" via the implicit * rule).
     */
    public function publicSuffix(string $host): string
    {
        $host = strtolower(rtrim($host, '.'));
        $labels = explode('.', $host);
        $count = count($labels);

        // Walk candidate suffixes from shortest (TLD) to longest, tracking the
        // longest matching rule. Exceptions cut one label off their own match.
        $best = $labels[$count - 1]; // implicit * rule
        for ($i = $count - 1; $i >= 0; $i--) {
            $candidate = implode('.', array_slice($labels, $i));

            if (isset($this->exceptions[$candidate])) {
                // Exception: the suffix is the candidate MINUS its first label.
                return implode('.', array_slice($labels, $i + 1));
            }
            if (isset($this->rules[$candidate])) {
                $best = $candidate;

                continue;
            }
            // "*.parent" matches candidate when candidate = <label>.parent
            $parent = implode('.', array_slice($labels, $i + 1));
            if ($parent !== '' && isset($this->wildcards[$parent])) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * eTLD+1, or null when the host IS a public suffix (nothing registrable).
     */
    public function registrableDomain(string $host): ?string
    {
        $host = strtolower(rtrim($host, '.'));
        $suffix = $this->publicSuffix($host);
        if ($host === $suffix) {
            return null;
        }
        $suffixLabels = substr_count($suffix, '.') + 1;
        $labels = explode('.', $host);
        if (count($labels) <= $suffixLabels) {
            return null;
        }

        return implode('.', array_slice($labels, -1 * ($suffixLabels + 1)));
    }

    private static function toAscii(string $domain): ?string
    {
        // Fast path: already ASCII.
        if (! preg_match('/[^\x20-\x7e]/', $domain)) {
            return strtolower($domain);
        }
        $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        return $ascii === false ? null : strtolower($ascii);
    }
}
