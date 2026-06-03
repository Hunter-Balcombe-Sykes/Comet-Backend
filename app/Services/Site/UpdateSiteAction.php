<?php

namespace App\Services\Site;

use App\Models\Core\HandleChangeLog;
use App\Models\Core\User\User;
use App\Models\Core\Site\UserHandleAlias;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteSubdomainAlias;
use App\Services\Cache\SiteCacheService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Site update with business logic — subdomain cooldown (30-day), PATCH-style
// settings merge, publish validation, skeleton selection. Per-user design
// vars are written via UpdateDesignKitAction (separate flow).
class UpdateSiteAction
{
    /**
     * Updates the given professional's site.
     *
     * $data should already be validated by a FormRequest.
     * $options can enable staff-only powers later without changing pro-behavior.
     */
    public function execute(User $professional, array $data, array $options = []): Site
    {
        $professional->loadMissing('site');

        $site = $professional->site;
        if (! $site) {
            throw ValidationException::withMessages([
                'site' => ['Professional has no site.'],
            ]);
        }

        $allowForcePublish = (bool) ($options['allow_force_publish'] ?? false);
        $forcePublish = (bool) ($data['force_publish'] ?? false);
        $allowSubdomainOverride = (bool) ($options['allow_subdomain_override'] ?? false);

        // IMPORTANT: never pass non-column fields into fill()
        unset($data['force_publish']);

        return DB::transaction(function () use ($professional, $site, $data, $options, $allowForcePublish, $forcePublish, $allowSubdomainOverride): Site {
            if (array_key_exists('subdomain', $data)) {
                $incoming = strtolower($data['subdomain']);
                $current = strtolower((string) $site->subdomain);

                if ($incoming === $current) {
                    unset($data['subdomain']);
                } else {
                    if (! $allowSubdomainOverride && $site->subdomain_changed_at) {
                        // Days between allowed subdomain changes; mirrored in UserSelfController::show.
                        $cooldownDays = (int) config('partna.handle.subdomain_cooldown_days', 30);
                        $nextAllowed = $site->subdomain_changed_at->copy()->addDays($cooldownDays);

                        if (Carbon::now()->lt($nextAllowed)) {
                            throw ValidationException::withMessages([
                                'subdomain' => ['You can change your subdomain again on '.$nextAllowed->toDateString().'.'],
                            ]);
                        }
                    }

                    $conflictInSites = DB::table('site.sites')
                        ->whereRaw('lower(subdomain) = ?', [$incoming])
                        ->where('id', '!=', $site->id)
                        ->exists();

                    if ($conflictInSites) {
                        throw ValidationException::withMessages([
                            'subdomain' => ['This subdomain is already taken.'],
                        ]);
                    }

                    // Exclude the current site's own aliases — renaming back to a
                    // previously held subdomain is allowed (the alias will be collapsed below).
                    $conflictInAliases = DB::table('site.site_subdomain_aliases')
                        ->whereRaw('lower(subdomain) = ?', [$incoming])
                        ->where('site_id', '!=', $site->id)
                        ->exists();

                    if ($conflictInAliases) {
                        throw ValidationException::withMessages([
                            'subdomain' => ['This subdomain is already taken.'],
                        ]);
                    }

                    $reclaimDays  = (int) config('partna.handle.reclaim_days', 14);
                    $redirectDays = (int) config('partna.handle.redirect_days', 90);

                    if (! empty($site->subdomain)) {
                        try {
                            SiteSubdomainAlias::query()->create([
                                'site_id'       => $site->id,
                                'subdomain'     => $site->subdomain,
                                'reclaim_until' => now()->addDays($reclaimDays),
                                'expires_at'    => now()->addDays($redirectDays),
                                'created_at'    => now(),
                            ]);
                        } catch (UniqueConstraintViolationException $e) {
                            // Alias row already exists — refresh lifecycle timestamps in case
                            // it was stale (e.g. a previous alias that expired and wasn't pruned yet).
                            // LIFE-1: typed catch handles only 23505 by construction — no SQLSTATE
                            // string-compare (Postgres-specific; getCode() is '23000' under SQLite).
                            SiteSubdomainAlias::query()
                                ->where('site_id', $site->id)
                                ->whereRaw('lower(subdomain) = ?', [strtolower((string) $site->subdomain)])
                                ->update([
                                    'reclaim_until' => now()->addDays($reclaimDays),
                                    'expires_at'    => now()->addDays($redirectDays),
                                ]);
                        }
                    }

                    // Keep the canonical handle on the professional in sync with the subdomain.
                    // HydrogenAffiliateController + public site resolver both look up by handle_lc,
                    // so a desync means the affiliate URL breaks immediately after a rename.
                    // The DB trigger (trg_professional_handle_change) records the old handle into
                    // user_handle_aliases automatically on this save. We also write it
                    // from PHP (belt-and-suspenders) so tests without the trigger stay green.
                    $oldHandle = $professional->handle;
                    if (! empty($oldHandle) && strtolower($oldHandle) !== $incoming) {
                        try {
                            UserHandleAlias::query()->create([
                                'user_id' => $professional->id,
                                'handle'          => $oldHandle,
                                'reclaim_until'   => now()->addDays($reclaimDays),
                                'expires_at'      => now()->addDays($redirectDays),
                                'created_at'      => now(),
                                'updated_at'      => now(),
                            ]);
                        } catch (UniqueConstraintViolationException $e) {
                            // Old handle is already aliased for this user — nothing to do.
                        }

                        $professional->forceFill([
                            'handle'    => $incoming,
                            'handle_lc' => $incoming,
                        ])->save();
                    }

                    // Collapse: if the user is renaming back to a subdomain they hold as an
                    // alias, drop that alias — they own it again, nothing to redirect from it.
                    SiteSubdomainAlias::query()
                        ->where('site_id', $site->id)
                        ->whereRaw('lower(subdomain) = ?', [$incoming])
                        ->delete();

                    // Audit log — record who changed what and from where. $current holds
                    // the old subdomain; $incoming is the new one. actor_id falls back to
                    // the professional themselves (self-serve rename).
                    HandleChangeLog::create([
                        'user_id' => (string) $professional->id,
                        'old_handle'      => $current,
                        'new_handle'      => $incoming,
                        'reason'          => (string) ($options['reason'] ?? HandleChangeLog::REASON_RENAME),
                        'actor_id'        => (string) ($options['actor_id'] ?? $professional->id),
                        'ip_address'      => $options['ip'] ?? null,
                        'user_agent'      => $options['user_agent'] ?? null,
                        'changed_at'      => now(),
                    ]);

                    $data['subdomain'] = $incoming;
                    $site->subdomain_changed_at = now();
                }
            }

            // Merge settings for PATCH semantics (don't overwrite the whole JSON).
            // settings.design.* is no longer accepted — the FormRequest rejects
            // those keys at validation, but strip them here too as a belt-and-
            // braces defence against any service-layer caller bypassing the
            // FormRequest (job replays, internal tools, etc.).
            if (array_key_exists('settings', $data)) {
                $existing = is_array($site->settings) ? $site->settings : [];
                $incoming = is_array($data['settings']) ? $data['settings'] : [];
                // Product selections are stored in commerce.affiliate_product_selections, not site settings JSON.
                unset($incoming['selected_products']);
                // Skeleton-system cleanup: settings.design.* is dead. Any
                // incoming `design` sub-key gets dropped on the floor.
                unset($incoming['design']);
                $merged = array_replace_recursive($existing, $incoming);
                // Same guard on the merged result — old design data on disk
                // was already stripped by the cleanup migration, but if any
                // straggler resurfaced via a write race it dies here.
                unset($merged['design']);

                $data['settings'] = $merged;
            }

            // If publishing, enforce completeness unless staff force_publish is allowed + true
            if (($data['is_published'] ?? null) === true) {
                $canBypass = $allowForcePublish && $forcePublish;

                if (! $canBypass) {
                    // Must have display name
                    if (empty($professional->display_name)) {
                        throw ValidationException::withMessages([
                            'is_published' => ['Cannot publish: professional must have a display name.'],
                        ]);
                    }

                }
            }

            // Future: staff-only overrides could go here (options['allow_force_publish'] etc.)

            $site->fill($data);
            try {
                $site->save();
            } catch (UniqueConstraintViolationException $e) {
                // Final safety net for the unique index on subdomain — e.g. a
                // concurrent rename that slipped past the pre-checks above.
                throw ValidationException::withMessages([
                    'subdomain' => ['This subdomain is already taken.'],
                ]);
            }

            return $site->fresh();
        });
    }
}
