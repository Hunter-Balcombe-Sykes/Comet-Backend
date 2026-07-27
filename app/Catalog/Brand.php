<?php

namespace App\Catalog;

use App\Catalog\Enums\Lifecycle;

// A brand identity in the catalog — the "who", independent of any surface
// (product/property) it exposes. Surface::$brandKey points back at
// Brand::$key.
final readonly class Brand
{
    public function __construct(
        public string $key,
        public string $displayName,
        public ?string $homepage = null,
        public Lifecycle $lifecycle = Lifecycle::Active,
        public ?string $successorKey = null,
    ) {}

    public static function make(string $key, string $displayName, ?string $homepage = null): self
    {
        return new self($key, $displayName, $homepage);
    }
}
