<?php

namespace App\Catalog;

use App\Catalog\Enums\EvidenceStrength;
use App\Catalog\Enums\EvidenceSurface;
use App\Catalog\Enums\IdentifierSource;

/**
 * One URL/DOM/DNS signature a surface (or, when surfaceKey is null, a shared
 * cross-surface signal referenced by signalKey) can be recognised by. $id is
 * deterministic — see computeId() — so recompiling the catalog from the same
 * builder chain always reproduces the same detector id across deploys.
 */
final readonly class Detector
{
    public function __construct(
        public string $id,
        public ?string $surfaceKey,
        public ?string $signalKey,
        public EvidenceSurface $evidence,
        public ?string $registrableKey,
        public ?string $subdomainPattern,
        public ?string $pathPattern,
        /** @var list<string> */
        public array $queryRequires = [],
        public ?string $fingerprint = null,
        public ?string $identifierCapture = null,
        public IdentifierSource $identifierSource = IdentifierSource::None,
        public ?string $probeCapability = null,
        public EvidenceStrength $strength = EvidenceStrength::ProfileLink,
        /** @var list<string> */
        public array $rejectPatterns = [],
        /** @var list<string> */
        public array $markets = [],
        public ?string $note = null,
    ) {}

    /**
     * Deterministic 16-hex-char id from the fields that define WHAT the
     * detector matches (not how strongly, nor its metadata): sha1 over the
     * JSON encoding of the discriminating fields, truncated.
     */
    public static function computeId(
        ?string $surfaceKey,
        ?string $signalKey,
        EvidenceSurface $evidence,
        ?string $registrableKey,
        ?string $subdomainPattern,
        ?string $pathPattern,
        array $queryRequires,
        ?string $fingerprint,
    ): string {
        $json = json_encode([
            $surfaceKey,
            $signalKey,
            $evidence->value,
            $registrableKey,
            $subdomainPattern,
            $pathPattern,
            $queryRequires,
            $fingerprint,
        ]);

        return substr(sha1((string) $json), 0, 16);
    }

    public static function url(string $registrableKey): DetectorBuilder
    {
        return new DetectorBuilder($registrableKey);
    }

    /** @return array<string, mixed> Snake_case shape for the compiled catalog artefact. */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'surface_key' => $this->surfaceKey,
            'signal_key' => $this->signalKey,
            'evidence' => $this->evidence->value,
            'registrable_key' => $this->registrableKey,
            'subdomain_pattern' => $this->subdomainPattern,
            'path_pattern' => $this->pathPattern,
            'query_requires' => $this->queryRequires,
            'fingerprint' => $this->fingerprint,
            'identifier_capture' => $this->identifierCapture,
            'identifier_source' => $this->identifierSource->value,
            'probe_capability' => $this->probeCapability,
            'strength' => $this->strength->value,
            'reject_patterns' => $this->rejectPatterns,
            'markets' => $this->markets,
            'note' => $this->note,
        ];
    }
}
