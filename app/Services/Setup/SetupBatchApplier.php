<?php

namespace App\Services\Setup;

use App\Catalog\CompiledCatalog;
use App\Jobs\Platforms\CommerceProbeJob;
use App\Jobs\Routing\VerifyLinkJob;
use App\Models\Content\Item;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\SectionItem;
use App\Models\Core\Site\Site;
use App\Models\Core\User\PreAccountBuildEvent;
use App\Models\Core\User\User;
use App\Routing\HiddenConnections;
use App\Routing\SuggestionApplier;
use App\Routing\Verification\LinkVerifier;
use App\Routing\WorkplaceCandidates;
use App\Services\Design\LogoCandidates;
use App\Services\PreAccount\BuildProgress;
use App\Site\Documents\SiteCacheLanes;
use App\Site\Pools\PoolRegistry;
use App\Site\Pools\PoolSectionProvisioner;
use Illuminate\Support\Facades\DB;

/**
 * One Continue = one request (A.9, wire §4). Applies in order: adopt →
 * accept (reveal a hidden pre-scrape row, or apply a proposed intent) →
 * select/exclude (the pool endpoints' own write shape). Per-entry errors,
 * never all-or-nothing — a half-good Continue still advances the person.
 *
 * teamMember is NOT applied here: the Fresha/Square team pick is a live
 * scrape with its own budget and locks (FreshaController::saveSelection),
 * and the dialog's BookingTeamStep already speaks to those endpoints.
 * The logo arm is wired by A.10's promote.
 */
class SetupBatchApplier
{
    public function __construct(
        private readonly SuggestionApplier $applier,
        private readonly HiddenConnections $hidden,
        private readonly WorkplaceCandidates $candidates,
        private readonly PoolSectionProvisioner $provisioner,
        private readonly LogoCandidates $logos,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{errors: array<string, string>}
     */
    public function apply(User $user, array $payload): array
    {
        $errors = [];
        $site = $user->site;

        $adopt = $payload['adopt'] ?? null;
        // 'connected:<id>' is the ALREADY-connected listing row SetupPayload
        // composes — adopting it is a no-op, not an error.
        if (is_string($adopt) && str_starts_with($adopt, 'connected:')) {
            $adopt = null;
        }
        if (is_string($adopt) && $adopt !== '') {
            try {
                if ($this->candidates->adopt($user, $adopt) === null) {
                    $errors['adopt'] = 'That listing is no longer offered.';
                }
            } catch (\Throwable $e) {
                report($e);
                $errors['adopt'] = 'Could not connect that listing.';
            }
        }

        foreach ((array) ($payload['accept'] ?? []) as $intentId) {
            try {
                if (! $this->acceptOne($user, (string) $intentId)) {
                    $errors['accept:'.$intentId] = 'That suggestion is no longer available.';
                }
            } catch (\Throwable $e) {
                report($e);
                $errors['accept:'.$intentId] = 'Could not connect that platform.';
            }
        }

        // Unticking a connected suggestion and pressing Continue DISCONNECTS
        // it (owner, 2026-09-03): the connection is torn down and the intent
        // reverts to proposed, so the row returns to the suggested band.
        foreach ((array) ($payload['disconnect'] ?? []) as $rowId) {
            try {
                if (! $this->disconnectOne($user, (string) $rowId)) {
                    $errors['disconnect:'.$rowId] = 'That platform is not connected.';
                }
            } catch (\Throwable $e) {
                report($e);
                $errors['disconnect:'.$rowId] = 'Could not disconnect that platform.';
            }
        }

        if (($payload['teamMember'] ?? null) !== null) {
            $errors['teamMember'] = 'Team pick goes through the booking selection endpoint.';
        }

        // A.10: the logo pass's pick — {"square": id, "full": id}, each slot
        // promoting one stored candidate to its real singleton.
        $logo = $payload['logo'] ?? null;
        if (is_array($logo) && $site instanceof Site) {
            foreach (['square', 'full'] as $slot) {
                $candidateId = $logo[$slot] ?? null;
                if (! is_string($candidateId) || $candidateId === '') {
                    continue;
                }
                try {
                    if (! $this->logos->promote($user, $site, $candidateId)) {
                        $errors['logo:'.$slot] = 'That logo candidate is no longer offered.';
                    }
                } catch (\Throwable $e) {
                    report($e);
                    $errors['logo:'.$slot] = 'Could not apply that logo.';
                }
            }
        }

        if ($site instanceof Site) {
            $touched = false;
            foreach ((array) ($payload['select'] ?? []) as $itemId) {
                $touched = $this->writeSelection($user, $site, (string) $itemId, SectionItem::STATE_PINNED, $errors) || $touched;
            }
            foreach ((array) ($payload['exclude'] ?? []) as $itemId) {
                $touched = $this->writeSelection($user, $site, (string) $itemId, SectionItem::STATE_EXCLUDED, $errors) || $touched;
            }
            if ($touched) {
                SiteCacheLanes::bust([(string) $site->id]);
            }
        }

        return ['errors' => $errors];
    }

    /** True when the id resolved to something acceptable and it was applied/revealed. */
    private function acceptOne(User $user, string $intentId): bool
    {
        // A hidden connection with no intent behind it (owner, 2026-09-04) —
        // Get Started's manual connect, per SetupPayload's mirror of the row
        // below. Same shape disconnectOne() already parses for a connection
        // without an intent; reveal is the accept-side counterpart.
        if (str_starts_with($intentId, 'connection:')) {
            $connection = IntegrationConnection::query()
                ->whereKey(substr($intentId, strlen('connection:')))
                ->where('user_id', $user->id)
                ->whereNull('deleted_at')
                ->first();
            if ($connection === null) {
                return false;
            }
            $this->hidden->reveal($connection);

            return true;
        }

        $intent = DB::table('routing.source_intents')
            ->where('id', $intentId)
            ->where('user_id', $user->id)
            ->first();
        if ($intent === null) {
            return false;
        }

        if ((string) $intent->state === 'applied') {
            // A pre-scraped row (A.4): the connection exists hidden — reveal it.
            $connection = IntegrationConnection::query()
                ->when($intent->connection_id !== null, fn ($q) => $q->whereKey($intent->connection_id))
                ->when($intent->connection_id === null, fn ($q) => $q
                    ->where('user_id', $user->id)
                    ->where('surface_key', $intent->surface_key)
                    ->where('resource_id', $intent->identifier))
                ->whereNull('deleted_at')
                ->first();
            if ($connection === null || (string) $connection->user_id !== (string) $user->id) {
                return false;
            }
            $this->hidden->reveal($connection);

            return true;
        }

        if (! in_array((string) $intent->state, ['proposed', 'blocked'], true)) {
            return false;
        }

        $surface = CompiledCatalog::surface((string) $intent->surface_key);
        if ($surface === null) {
            return false;
        }

        // A probed storefront (Shopify / WooCommerce / Squarespace / Big
        // Cartel) needs more than the bare connection SuggestionApplier::
        // apply() writes: the store collection, name, logo, and the shop cap
        // / tombstone checks StoreBrandSeeder runs — which is also the only
        // thing that dispatches ShopInitialFillJob. Mirrors
        // SuggestionsController::accept()'s identical arm (2026-09-05): this
        // dialog is the OTHER accept lane, and before this fix it had no
        // equivalent, so ticking a store here (JRLUSA, squeakprobarber
        // signup) created a connection with no storefront, no catalogue and
        // no products step ever appearing. Queue-only — the probe answer is
        // cached 12h, so this is seconds — and the job settles the intent
        // itself (applied, or blocked with the reason the inbox already
        // renders); nothing below this block runs for a shop surface.
        if (in_array($intent->surface_key, SuggestionApplier::PROBED_STORE_SURFACES, true)
            && is_string($intent->canonical_url ?? null) && $intent->canonical_url !== '') {
            // Owed from the tick (2026-09-05): ShopInitialFillJob writes this
            // same note when it starts, but that is a probe + a seeder + a
            // queue hop away, and until then items.shop read ready-and-empty
            // — so the dialog's "hold Continue until the products are ready"
            // had nothing to hold on and "Your products" was empty until a
            // refresh (squeakprobarber, st_ali). One STARTED per stage, so
            // the job's own is a no-op.
            BuildProgress::noteForUser((string) $user->id, PreAccountBuildEvent::STAGE_SHOP, PreAccountBuildEvent::STATUS_STARTED, 'Syncing your store');
            CommerceProbeJob::dispatch((string) $user->id, (string) $intent->canonical_url, 'shop', acceptedIntentId: (string) $intent->id);

            return true;
        }

        // L2, same rule as the suggestions inbox (2026-09-03). The setup dialog
        // is the OTHER accept lane, and the owner's rule is one system for both:
        // a link does not become a live CTA until something says the page is
        // real, or honestly says it could not check.
        //
        // Returns true, not false: the person's tick was accepted. What is
        // pending is our own check, and the dialog renders that from the
        // intent's 'verifying' state rather than from a failed row.
        if (is_string($intent->canonical_url ?? null) && $intent->canonical_url !== ''
            && app(LinkVerifier::class)->canVerify((string) $intent->surface_key)) {
            DB::table('routing.source_intents')
                ->where('id', $intent->id)
                ->where('user_id', $user->id)
                ->update(['state' => 'verifying', 'updated_at' => now()]);

            VerifyLinkJob::dispatch((string) $user->id, (string) $intent->id);

            return true;
        }

        $this->applier->apply($user, $intent, $surface);

        return true;
    }

    /**
     * Tear down one connected suggestion (wire §4 `disconnect`). The row id is
     * an intent id, or `connection:<id>` for a connection SetupPayload
     * surfaced without an intent. The connection is model-deleted (observer
     * cascade cleans up) and any intent reverts to proposed so the row goes
     * back to the suggested band.
     */
    private function disconnectOne(User $user, string $rowId): bool
    {
        if (str_starts_with($rowId, 'connection:')) {
            $connection = IntegrationConnection::query()
                ->whereKey(substr($rowId, strlen('connection:')))
                ->where('user_id', $user->id)
                ->whereNull('deleted_at')
                ->first();
            if ($connection === null) {
                return false;
            }
            $connection->delete();

            return true;
        }

        $intent = DB::table('routing.source_intents')
            ->where('id', $rowId)
            ->where('user_id', $user->id)
            ->first();
        if ($intent === null) {
            return false;
        }

        $connection = IntegrationConnection::query()
            ->when($intent->connection_id !== null, fn ($q) => $q->whereKey($intent->connection_id))
            ->when($intent->connection_id === null, fn ($q) => $q
                ->where('surface_key', $intent->surface_key)
                ->where('resource_id', $intent->identifier))
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->first();
        if ($connection !== null) {
            $connection->delete();
        }

        DB::table('routing.source_intents')
            ->where('id', $intent->id)
            ->update([
                'state' => 'proposed',
                'connection_id' => null,
                'resolved_at' => null,
                'updated_at' => now(),
            ]);

        return $connection !== null || (string) $intent->state === 'applied';
    }

    /** @param  array<string, string>  $errors */
    private function writeSelection(User $user, Site $site, string $itemId, string $state, array &$errors): bool
    {
        $item = Item::query()->whereKey($itemId)->where('user_id', $user->id)->whereNull('removed_at')->first();
        if ($item === null) {
            $errors[($state === SectionItem::STATE_PINNED ? 'select:' : 'exclude:').$itemId] = 'No such item.';

            return false;
        }

        $pool = PoolRegistry::poolForKind((string) $item->kind);
        if ($pool === null || ($state === SectionItem::STATE_PINNED && ! PoolRegistry::allowsPin($pool))) {
            $errors[($state === SectionItem::STATE_PINNED ? 'select:' : 'exclude:').$itemId] = 'That item cannot be selected.';

            return false;
        }

        $section = $this->provisioner->ensure($site, $pool);

        $row = SectionItem::query()
            ->where('section_id', $section->id)
            ->where('item_id', $item->id)
            ->first() ?? new SectionItem;
        $row->section_id = (string) $section->id;
        $row->item_id = (string) $item->id;
        $row->state = $state;
        if ($state === SectionItem::STATE_EXCLUDED) {
            $row->sort_key = null;
        }
        if (! $row->exists) {
            $row->created_at = now();
        }
        $row->save();

        return true;
    }
}
