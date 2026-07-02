<?php

namespace App\Rules;

use App\Models\Core\Site\Site;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Validates subdomain uniqueness for both user-facing and staff site update
// requests. Accepts the current site ID (to exclude self from the uniqueness
// check) and the professional model (to exclude their own handle aliases).
// Enforces 4 checks: reserved words, case-insensitive uniqueness on site.sites,
// active subdomain alias, and active handle alias.
class SubdomainValidationRule implements ValidationRule
{
    public function __construct(
        private readonly ?string $currentSiteId,
        private readonly mixed $professional,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Check reserved words
        $reserved = array_map('strtolower', config('partna.reserved_subdomains', []));
        if (in_array(strtolower($value), $reserved, true)) {
            $fail('The subdomain "'.$value.'" is reserved and cannot be used.');

            return;
        }

        // Check case-insensitive uniqueness
        $exists = Site::whereRaw('lower(subdomain) = ?', [strtolower($value)])
            ->when($this->currentSiteId, function ($query) {
                $query->where('id', '!=', $this->currentSiteId);
            })
            ->exists();

        if ($exists) {
            $fail('This subdomain is already taken.');

            return;
        }

        // Only ACTIVE aliases reserve a subdomain — an expired alias has
        // released the name back to the pool and must not block a new claim.
        $aliasExists = DB::table('site.site_subdomain_aliases')
            ->whereRaw('lower(subdomain) = ?', [strtolower($value)])
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();

        if ($aliasExists) {
            $fail('This subdomain is already taken.');

            return;
        }

        // Also block handles claimed by another professional's old handle alias.
        // These are preserved for redirect/SEO purposes and must not be re-used.
        // (Active aliases only — expired ones have lapsed back to the pool.)
        $currentUserId = $this->professional?->id;

        try {
            $existsInUserAliases = DB::connection('pgsql')
                ->table('core.user_handle_aliases')
                ->whereRaw('LOWER(handle) = LOWER(?)', [$value])
                ->where('user_id', '!=', $currentUserId)
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->exists();
        } catch (QueryException $e) {
            report($e);
            Log::warning('Professional alias check failed in SubdomainValidationRule', [
                'error' => $e->getMessage(),
                'professional_id' => $this->professional?->id,
                'operation' => 'subdomain_alias_check',
            ]);
            $existsInUserAliases = false;
        }

        if ($existsInUserAliases) {
            $fail('This subdomain is already taken.');
        }
    }
}
