<?php

namespace App\Services\PreAccount;

use App\Services\PreAccount\Generators\SiteSourceGenerator;

// Resolves a source_type string to its generator instance via the
// config('partna.pre_account.generators') registry — the ONE map a third
// source needs to extend (see config/partna.php).
class SourceGeneratorRegistry
{
    public function for(string $sourceType): SiteSourceGenerator
    {
        $class = config("partna.pre_account.generators.{$sourceType}");
        if (! is_string($class) || ! is_a($class, SiteSourceGenerator::class, true)) {
            throw new \InvalidArgumentException("Unknown pre-account source type '{$sourceType}'.");
        }

        return app($class);
    }
}
