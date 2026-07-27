<?php

namespace App\Catalog;

use App\Catalog\Enums\EvidenceStrength;
use App\Catalog\Enums\EvidenceSurface;
use App\Catalog\Enums\IdentifierSource;
use InvalidArgumentException;

/**
 * Mutable fluent assembly for a Detector. Entry points: Detector::url() for
 * the common registrable-domain case (sets $registrableKey), or `new self`
 * directly for a signal-only detector chained with ->signal() —
 * SurfaceBuilder::signalDetect() takes builders made this way.
 */
final class DetectorBuilder
{
    private ?string $subdomainPattern = null;

    private ?string $pathPattern = null;

    /** @var list<string> */
    private array $queryRequires = [];

    private ?string $identifierCapture = null;

    private IdentifierSource $identifierSource = IdentifierSource::None;

    private EvidenceSurface $evidence = EvidenceSurface::Url;

    private ?string $fingerprint = null;

    private ?string $probeCapability = null;

    /** @var list<string> */
    private array $rejectPatterns = [];

    private ?string $signalKey = null;

    /** @var list<string> */
    private array $markets = [];

    private ?string $note = null;

    private EvidenceStrength $strength = EvidenceStrength::ProfileLink;

    public function __construct(private readonly ?string $registrableKey = null) {}

    public function path(string $regex): self
    {
        $this->pathPattern = $regex;

        return $this;
    }

    public function subdomain(string $regex): self
    {
        $this->subdomainPattern = $regex;

        return $this;
    }

    public function query(string ...$params): self
    {
        $this->queryRequires = $params;

        return $this;
    }

    /** Sets identifierCapture — the named capture group identifying the surface's identifier. */
    public function captures(string $name): self
    {
        $this->identifierCapture = $name;

        return $this;
    }

    public function from(IdentifierSource $s): self
    {
        $this->identifierSource = $s;

        return $this;
    }

    public function strength(EvidenceStrength $s): self
    {
        $this->strength = $s;

        return $this;
    }

    public function evidence(EvidenceSurface $e): self
    {
        $this->evidence = $e;

        return $this;
    }

    public function fingerprint(string $f): self
    {
        $this->fingerprint = $f;

        return $this;
    }

    public function probe(string $capability): self
    {
        $this->probeCapability = $capability;

        return $this;
    }

    public function reject(string ...$regexes): self
    {
        $this->rejectPatterns = $regexes;

        return $this;
    }

    public function signal(string $key): self
    {
        $this->signalKey = $key;

        return $this;
    }

    public function markets(string ...$m): self
    {
        $this->markets = $m;

        return $this;
    }

    public function note(string $n): self
    {
        $this->note = $n;

        return $this;
    }

    /**
     * Signal-only detectors pass null $surfaceKey and must have called
     * signal(); every other detector must have a surfaceKey and no
     * signalKey.
     */
    public function build(?string $surfaceKey): Detector
    {
        $hasSurface = $surfaceKey !== null;
        $hasSignal = $this->signalKey !== null;

        if ($hasSurface === $hasSignal) {
            throw new InvalidArgumentException(
                'A detector must have exactly one of surfaceKey or signalKey — not both, not neither.'
            );
        }

        $id = Detector::computeId(
            surfaceKey: $surfaceKey,
            signalKey: $this->signalKey,
            evidence: $this->evidence,
            registrableKey: $this->registrableKey,
            subdomainPattern: $this->subdomainPattern,
            pathPattern: $this->pathPattern,
            queryRequires: $this->queryRequires,
            fingerprint: $this->fingerprint,
        );

        return new Detector(
            id: $id,
            surfaceKey: $surfaceKey,
            signalKey: $this->signalKey,
            evidence: $this->evidence,
            registrableKey: $this->registrableKey,
            subdomainPattern: $this->subdomainPattern,
            pathPattern: $this->pathPattern,
            queryRequires: $this->queryRequires,
            fingerprint: $this->fingerprint,
            identifierCapture: $this->identifierCapture,
            identifierSource: $this->identifierSource,
            probeCapability: $this->probeCapability,
            strength: $this->strength,
            rejectPatterns: $this->rejectPatterns,
            markets: $this->markets,
            note: $this->note,
        );
    }
}
