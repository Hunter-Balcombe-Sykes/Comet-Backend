<?php

namespace App\Ingest\Manifest;

/**
 * One stream a connector produces. Everything the runtime needs to know about
 * how to treat this stream's records is declared here rather than inferred:
 * what must be present for the shape to be sane, what varies without meaning
 * anything, whether order lets us conclude absence, and which profile governs
 * cadence and deletion.
 */
final readonly class StreamSpec
{
    /**
     * @param  string  $name  'releases' | 'reviews' | 'media' | …
     * @param  string  $target  projection target: an item kind, or 'none'
     * @param  list<string>  $requires  JSON paths that MUST be present (violation ⇒ Shape)
     * @param  list<string>  $volatile  paths excluded from doc_hash (rotating CDN params etc.)
     * @param  ?string  $orderField  enables prefix domination; null ⇒ this stream can NEVER delete
     * @param  list<string>  $authoritativeFields  identity streams only: fields this source may clear
     */
    public function __construct(
        public string $name,
        public string $target,
        public SourceProfile $profile,
        public array $requires = [],
        public array $volatile = [],
        public ?string $orderField = null,
        public array $authoritativeFields = [],
    ) {}

    /**
     * Absence can only ever mean deletion when the profile allows it AND the
     * stream declares an order field to reason about coverage with. Both, or
     * nothing is ever removed.
     */
    public function mayDelete(): bool
    {
        return $this->profile->mayDelete() && $this->orderField !== null;
    }
}
