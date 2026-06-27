<?php

namespace App\Rules;

use App\Services\Platforms\Registry\PlatformRegistry;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

// Validates that a platform key is registered in the PlatformRegistry — the
// app-level replacement for the DB CHECK constraint. Resolves the singleton so
// adding a platform (one descriptor) is automatically accepted, no migration.
class PlatformInRegistry implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! app(PlatformRegistry::class)->has($value)) {
            $fail('The selected :attribute is not a supported platform.');
        }
    }
}
