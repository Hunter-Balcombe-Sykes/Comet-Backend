<?php

namespace App\Routing;

/**
 * A canonicalised URL, decomposed into exactly what the projector matches on.
 * Immutable; only IriCanonicalizer constructs it. `rejected` carries the
 * reason a URL never became routable at all (scheme, own-infra, confusable
 * host…) — those are verdicts, not exceptions, because the router must record
 * them as observations rather than throw.
 */
final readonly class Iri
{
    public function __construct(
        public string $raw,
        public ?string $canonical,
        public ?string $scheme,
        public ?string $host,
        public ?string $registrableKey,
        public ?string $subdomain,
        public string $path,
        /** @var array<string, string> */
        public array $query,
        public ?int $port,
        public ?string $rejected = null,
        /** Host this was aliased from, when Hosts::aliases() rewrote it. */
        public ?string $aliasedFrom = null,
        /** True when the registrable key came from a suffix override. */
        public bool $tenantScoped = false,
        /** The platform suffix a tenant-scoped host sits under (myshopify.com). */
        public ?string $suffixParent = null,
        /** The tenant label itself (acme in acme.myshopify.com). */
        public ?string $tenantLabel = null,
    ) {}

    /**
     * Registrable keys whose detectors could apply, most specific first: a
     * tenant host also answers to its platform suffix, because that is where
     * the platform's own rules are registered.
     *
     * @return list<string>
     */
    public function candidateKeys(): array
    {
        if ($this->registrableKey === null) {
            return [];
        }

        return $this->suffixParent !== null && $this->suffixParent !== $this->registrableKey
            ? [$this->registrableKey, $this->suffixParent]
            : [$this->registrableKey];
    }

    public function isRoutable(): bool
    {
        return $this->rejected === null && $this->registrableKey !== null;
    }

    public static function reject(string $raw, string $reason): self
    {
        return new self($raw, null, null, null, null, null, '', [], null, $reason);
    }
}
