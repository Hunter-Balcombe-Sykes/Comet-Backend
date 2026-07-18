<?php

namespace App\Services\PreAccount\Generators;

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;

// MINIMAL STUB — normalizeRef/dedupeKey/handleSeed are complete; generate()
// (seeder extraction + scrape wiring) is completed in Task 8.
class InstagramSourceGenerator implements SiteSourceGenerator
{
    public function normalizeRef(string $raw): string
    {
        $ref = mb_strtolower(ltrim(trim($raw), '@'));

        if ($ref === '' || ! preg_match('/^[a-z0-9._]{1,30}$/', $ref)) {
            throw new \InvalidArgumentException('That does not look like an Instagram handle.');
        }

        return $ref;
    }

    public function dedupeKey(string $normalizedRef): string
    {
        return $normalizedRef;
    }

    public function handleSeed(string $normalizedRef, ?string $sourceName): string
    {
        return $normalizedRef;
    }

    public function generate(User $user, Site $site, string $sourceRef): void
    {
        // Completed in Task 8 (seeder extraction + scrape wiring).
        throw new \LogicException('InstagramSourceGenerator::generate is completed in Task 8.');
    }
}
