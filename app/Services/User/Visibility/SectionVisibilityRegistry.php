<?php

namespace App\Services\User\Visibility;

// Single source of truth for which section types have a visibility rule. Bound as
// a singleton in SectionVisibilityServiceProvider. Mirrors PlatformRegistry.
class SectionVisibilityRegistry
{
    /** @var array<string, SectionVisibilityContract> */
    private array $rules = [];

    public function register(SectionVisibilityContract $rule): self
    {
        $this->rules[$rule->blockType()] = $rule;

        return $this;
    }

    public function get(string $blockType): ?SectionVisibilityContract
    {
        return $this->rules[$blockType] ?? null;
    }

    /** @return array<string, SectionVisibilityContract> */
    public function all(): array
    {
        return $this->rules;
    }
}
