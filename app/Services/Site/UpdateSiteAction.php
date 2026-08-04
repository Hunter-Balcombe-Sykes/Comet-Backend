<?php

namespace App\Services\Site;

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Site update with business logic — subdomain cooldown (30-day), PATCH-style
// settings merge, publish validation, skeleton selection. Per-user design
// vars are written via UpdateDesignKitAction (separate flow).
class UpdateSiteAction
{
    /**
     * Settings keys whose values are LISTS and must replace atomically.
     * array_replace_recursive merges lists positionally — a shorter incoming
     * list would silently inherit the old list's tail entries (e.g. manual
     * page order [a,b,c] PATCHed with [b] must become [b], not [b,b,c]).
     *
     * @var list<string>
     */
    public const LIST_SETTINGS_KEYS = ['manual_page_order', 'manual_actions', 'manual_order_pools'];

    public function __construct(private readonly RenameSubdomainAction $renameSubdomain) {}

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

        // IMPORTANT: never pass non-column fields into fill()
        unset($data['force_publish']);

        // Hoist pure-PHP work out of the transaction to keep the lock window narrow.
        // NOTE: the settings MERGE itself does NOT happen here — see LIFE-3 below.
        // Only the incoming payload is prepped (dead-key stripping); the raw
        // 'settings' key is removed from $data so it can't leak into fill()
        // unmerged.

        // LIFE-3: settings PATCH-semantics used to merge against
        // $site->settings — a pre-transaction in-memory snapshot loaded by
        // loadMissing('site') before this method even opens its transaction.
        // Two concurrent PATCHes both read that same starting snapshot, and
        // whichever commits last silently overwrote the other's write with no
        // error. The merge now happens INSIDE the transaction against a
        // lockForUpdate() re-read of the on-disk value (below) — only the
        // incoming side of the merge is prepped here, outside the lock window.
        $hasSettings = array_key_exists('settings', $data);
        $incomingSettings = [];
        if ($hasSettings) {
            $incomingSettings = is_array($data['settings']) ? $data['settings'] : [];
            // Affiliate/product-selection feature was removed with the commerce schema; strip any
            // legacy key so it can't reappear in site settings JSON.
            unset($incomingSettings['selected_products']);
            // Skeleton-system cleanup: settings.design.* is dead. Any
            // incoming `design` sub-key gets dropped on the floor.
            unset($incomingSettings['design']);
        }
        // Never let the raw incoming settings reach fill() — the merged value
        // is computed inside the transaction and assigned back before fill().
        unset($data['settings']);

        // If publishing, enforce completeness unless staff force_publish is allowed + true.
        // Throwing before the transaction is strictly better — no transaction to roll back
        // on a validation failure.
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

        return DB::connection('pgsql')->transaction(function () use ($professional, $site, $data, $hasSettings, $incomingSettings, $options): Site {
            if (array_key_exists('subdomain', $data)) {
                $this->renameSubdomain->execute($site, $data['subdomain'], $professional, $options);
                unset($data['subdomain']); // staged onto $site by the rename action
            }

            // LIFE-3: merge settings for PATCH semantics against a LOCKED
            // re-read of the on-disk value, not the pre-transaction in-memory
            // snapshot. The FOR UPDATE blocks a concurrent writer until this
            // transaction commits, so the second writer's merge sees the
            // first writer's result instead of clobbering it. Raw query
            // (bypasses the model's cached attribute + array cast), so the
            // returned value is JSON text that needs an explicit decode.
            if ($hasSettings) {
                $lockedRaw = DB::connection('pgsql')->table('site.sites')->where('id', $site->id)->lockForUpdate()->value('settings');
                $decoded = json_decode((string) $lockedRaw, true);
                $existing = is_array($decoded) ? $decoded : [];

                $merged = array_replace_recursive($existing, $incomingSettings);
                // Same guard on the merged result — old design data on disk
                // was already stripped by the cleanup migration, but if any
                // straggler resurfaced via a write race it dies here.
                unset($merged['design']);

                // List-valued settings replace atomically (see LIST_SETTINGS_KEYS —
                // recursive replace would positionally merge the old list's tail
                // into a shorter incoming one).
                foreach (self::LIST_SETTINGS_KEYS as $listKey) {
                    if (array_key_exists($listKey, $incomingSettings)) {
                        $merged[$listKey] = $incomingSettings[$listKey];
                    }
                }

                // FOUND-16: hoist the promoted keys out of settings JSONB into
                // typed columns. We extract from $merged (post-PATCH) so only keys
                // the client actually sent are written — columns the request didn't
                // touch keep their existing DB value. The client still SENDS these
                // under settings.* (no frontend change); columns are the source of
                // truth. Phase 2 (strip active): the key is removed from $merged so
                // new writes no longer populate the JSONB mirror — the column is the
                // sole write target. Migration 20260701200000 re-injects columns into
                // both public-read views so the emitted settings blob is byte-identical.
                foreach (Site::PROMOTED_SETTINGS_KEYS as $key) {
                    if (array_key_exists($key, $merged)) {
                        $data[$key] = $merged[$key];
                        // Phase 2: strip the key from settings so the column is the
                        // sole write target. The views re-inject columns into the
                        // emitted settings blob (migration 20260701200000), so the
                        // wire shape is byte-identical — no frontend change required.
                        unset($merged[$key]);
                    }
                }

                $data['settings'] = $merged;
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
