<?php

namespace App\Services\Platforms;

use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\User;
use App\Services\Profile\SectorTaxonomy;
use Illuminate\Support\Str;

/**
 * Fold Instagram-scraped identity fields into a user's core.users columns —
 * always fill-if-empty, both account types equally (unlike Google Business,
 * which is authoritative for a Business account via IdentitySync). Instagram
 * is a supplementary source: it only ever closes gaps, never overrides
 * anything already set — by hand, by Google, or by an earlier Instagram sync.
 */
class InstagramIdentitySync
{
    private const INSTAGRAM_SOURCE = 'instagram';

    public function applyIdentity(User $user, array $payload): void
    {
        $this->applySector($user, $this->stringOrNull($payload['businessCategoryName'] ?? null));
        $this->applyDisplayName($user, $this->stringOrNull($payload['fullName'] ?? null));
        $this->applyHandle($user, $this->stringOrNull($payload['username'] ?? null));
        $this->applyContactFields($user, $payload);
    }

    private function applySector(User $user, ?string $category): void
    {
        $mapped = SectorTaxonomy::fromInstagramCategory($category);
        if ($mapped === null) {
            return;
        }
        // A manual pick, or a value already stamped by any source, is
        // permanent from Instagram's perspective — fill-if-empty only.
        if ($user->sector_source === 'manual' && $user->sector !== null) {
            return;
        }
        if ($this->isBlank($user->sector)) {
            $user->sector = $mapped;
            $user->sector_source = self::INSTAGRAM_SOURCE;
            $user->save();
        }
    }

    private function applyDisplayName(User $user, ?string $fullName): void
    {
        if ($fullName === null || ! $this->isBlank($user->display_name)) {
            return;
        }
        $user->display_name = $fullName;
        $user->save();
    }

    private function applyHandle(User $user, ?string $username): void
    {
        if ($username === null || ! $this->isBlank($user->handle)) {
            return;
        }
        $base = Str::slug($username, '-') ?: 'user';
        $candidate = $base;
        $attempt = 1;
        while (User::query()->where('handle_lc', strtolower($candidate))->exists()) {
            $candidate = $base.'-'.$attempt;
            $attempt++;
        }
        $user->handle = $candidate;
        $user->handle_lc = strtolower($candidate);
        $user->save();
    }

    private function applyContactFields(User $user, array $payload): void
    {
        $email = $this->stringOrNull($payload['businessEmail'] ?? null);
        $phone = $this->stringOrNull($payload['businessPhoneNumber'] ?? null);
        if ($email === null && $phone === null) {
            return;
        }
        $site = Site::query()->where('user_id', $user->id)->first();
        if ($site === null) {
            return;
        }
        $workplace = Workplace::firstOrNew(['site_id' => (string) $site->id]);
        $sources = is_array($workplace->field_sources) ? $workplace->field_sources : [];
        $stamp = now()->toIso8601String();
        $changed = false;
        foreach (['contact_email' => $email, 'phone' => $phone] as $field => $value) {
            if ($value === null || ! $this->isBlank($workplace->{$field} ?? null)) {
                continue;
            }
            $workplace->{$field} = $value;
            $sources[$field] = ['source' => self::INSTAGRAM_SOURCE, 'at' => $stamp];
            $changed = true;
        }
        if ($changed) {
            $workplace->field_sources = $sources;
            $workplace->save();
        }
    }

    private function isBlank($value): bool
    {
        return $value === null || $value === '';
    }

    private function stringOrNull($value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
