<?php

// Active lane: seed two identities that each own distinct resources — a
// site, a gallery media row, a customer, and an enquiry. Known ids are the
// cross-identity IDOR targets for Phase 4's active scan (token A against
// B's resource ids). Deterministic: fixed handles, no randomness, because
// each run targets a fresh throwaway scratch DB (see bring-up.sh).
//
// Usage: php active/seed-identities.php --env=dast <OUTDIR>
// Must run with --env=dast so Laravel loads .env.dast (see bring-up.sh) —
// verified 2026-07-26 that a plain bootstrapped script (not just artisan)
// honours --env=X via Illuminate's own ArgvInput scan, the same mechanism
// `artisan --env=dast` uses. Writes $OUTDIR/identities.json.

use App\Models\Core\Site\Enquiry;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\Customer;
use App\Models\Core\User\User;
use App\Services\Content\LinkPoolWriter;
use App\Site\Pools\PoolSectionProvisioner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// The bootstrap MUST sit below the `use` block, not above it. PHP registers
// namespace imports linearly as it compiles a file, so a `use` declared after a
// call site does not apply to it — with the bootstrap first, `Kernel::class`
// compiles to the literal string 'Kernel' and the container dies with
// "Target class [Kernel] does not exist", killing the whole active lane.
//
// That is exactly how 6461cff5 (a PINT-ONLY commit, 2026-07-28) broke this
// script: Pint rewrote the original fully-qualified
// `Illuminate\Contracts\Console\Kernel::class` into a short reference plus an
// import, and placed the import below the call. Nothing caught it because this
// lane is manual-only. Keep this ordering — it is Pint-stable and correct.
// CONTAINMENT GUARD 1 — .env.dast must exist before anything boots.
// Without it, `--env=dast` finds no dast environment file and Laravel falls
// back to the repo's REAL .env, so this seeder would create users and write
// rows against whatever SUPABASE_URL/DB_HOST the developer actually uses.
// That is not theoretical: on 2026-08-07 a stale bring-up.env let this script
// run before bring-up.sh had written .env.dast, and it POSTed to a remote
// *.supabase.co admin endpoint — harmless only because the host was
// unreachable. mint-jwt.php has always had this check; this file did not.
$envDast = __DIR__.'/../../../.env.dast';
if (! file_exists($envDast)) {
    fwrite(STDERR, "$envDast not found — the active lane's scratch stack is not up. Refusing to seed: without .env.dast this would target the REAL .env's Supabase/DB. Run via zap-active.sh, never directly.\n");
    exit(1);
}

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// CONTAINMENT GUARD 2 — the resolved targets must be loopback, full stop.
// Guard 1 proves a dast env file exists; this proves it actually points at the
// throwaway stack. Belt and braces on purpose: this script creates auth users
// and writes rows, so "NEVER point this at real dev/prod" (bring-up.sh) needs
// an assertion, not just a comment. Checked AFTER bootstrap because it reads
// the resolved config rather than re-parsing the file.
foreach ([
    'supabase.url' => config('supabase.url'),
    'database.connections.pgsql.host' => config('database.connections.pgsql.host'),
] as $key => $value) {
    $host = parse_url((string) $value, PHP_URL_HOST) ?: (string) $value;
    if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
        fwrite(STDERR, "refusing to seed: $key resolves to '$host', which is not loopback. The active lane must only ever touch its own throwaway stack.\n");
        exit(1);
    }
}

$outdir = null;
foreach (array_slice($argv, 1) as $arg) {
    if (! str_starts_with($arg, '--')) {
        $outdir = $arg;
        break;
    }
}
if (! $outdir) {
    fwrite(STDERR, "usage: php active/seed-identities.php --env=dast <OUTDIR>\n");
    exit(1);
}

/**
 * core.users.auth_user_id carries a real FK to auth.users (discovered the
 * hard way: an arbitrary UUID 23503-violates it) — the row must exist via
 * Supabase Auth itself, not be invented. Creates it through the local
 * GoTrue admin API (service-role key), which is the supported way to
 * create an Auth user server-side and is what a real signup flow's
 * Auth-first step does anyway. The password is returned (not just
 * discarded) because mint-jwt.php needs it — see that file for why
 * direct-signing a token doesn't work against this GoTrue version.
 */
function createAuthUser(string $email, string $password): string
{
    $response = Http::withHeaders([
        'apikey' => config('supabase.service_role_key'),
        'Authorization' => 'Bearer '.config('supabase.service_role_key'),
    ])->post(rtrim(config('supabase.url'), '/').'/auth/v1/admin/users', [
        'email' => $email,
        'email_confirm' => true,
        'password' => $password,
    ]);

    if (! $response->successful()) {
        fwrite(STDERR, "Auth admin user creation failed: {$response->status()} {$response->body()}\n");
        exit(1);
    }

    return $response->json('id');
}

function seedIdentity(string $label): array
{
    $email = "dast-identity-{$label}@example.invalid";
    $password = 'dast-seed-'.bin2hex(random_bytes(12));
    $authUserId = createAuthUser($email, $password);

    $user = new User([
        'handle' => "dast-identity-{$label}",
        'handle_lc' => "dast-identity-{$label}",
        'display_name' => "DAST Identity {$label}",
        'first_name' => 'DAST',
        'last_name' => strtoupper($label),
        'account_type' => 'partna',
    ]);
    $user->auth_user_id = $authUserId;
    $user->save();

    $site = new Site([
        'subdomain' => "dast-identity-{$label}",
        'is_published' => true,
    ]);
    $site->user()->associate($user);
    $site->save();

    $media = new SiteMedia([
        'path' => "dast/{$label}/gallery-1.jpg",
        'pool' => 'content',
        'media_type' => 'image',
    ]);
    $media->site_id = $site->id;
    $media->save();

    $customer = new Customer([
        'email' => "dast-identity-{$label}@example.invalid",
        'full_name' => "DAST Customer {$label}",
        'source' => 'dast-seed',
    ]);
    $customer->user_id = $user->id;
    $customer->save();

    $enquiry = new Enquiry([
        'name' => "DAST Enquirer {$label}",
        'email' => "dast-enquiry-{$label}@example.invalid",
        'subject' => 'DAST seed enquiry',
        'message' => "Seeded by active/seed-identities.php for identity {$label}.",
    ]);
    $enquiry->user_id = $user->id;
    $enquiry->site_id = $site->id;
    $enquiry->save();

    return [
        'id' => $user->id,
        'auth_user_id' => $authUserId,
        'email' => $email,
        'password' => $password,
        'handle' => $user->handle,
        'site_id' => $site->id,
        'media_id' => $media->id,
        'customer_id' => $customer->id,
        'enquiry_id' => $enquiry->id,
        ...seedProbeFixtures($user, $site, $label),
    ];
}

/**
 * One fixture per IDOR ownership group beyond the two the lane started with.
 *
 * Seeded IDENTICALLY for A and B, deliberately: B's copies exist so each probe
 * 404s for the RIGHT reason (wrong owner) rather than because the row is absent
 * or in the wrong pool — a probe that 404s for the wrong reason is a vacuous
 * pass, which is the entire failure class this lane exists to eliminate.
 *
 * RAW INSERTS, no Eloquent, no observers — same discipline as seedFirstComeBuild()
 * below and for the same reason: QUEUE_CONNECTION=sync in .env.dast runs every
 * observer-dispatched job INLINE. Raw inserts also let the columns be checked
 * against supabase/migrations/ DDL directly rather than against a model's
 * $fillable, which is what CLAUDE.md asks for on constraint-bound writes (the
 * lane runs real Postgres; the test suite runs SQLite).
 *
 * FIXTURE → CONSUMER. NINE probes are DELETE, and two more (restyle-undo,
 * routing-suggestion-dismiss) are single-use state transitions — a DELETE or a
 * transition *control* runs against identity A's own row and consumes it:
 *   - restyle-undo: DesignKitRestyleService::undo throws ValidationException
 *     (422) once undone_at is set.
 *   - routing-suggestion-dismiss: sets state='dismissed', and findIntent filters
 *     state IN ('proposed','blocked').
 * Every consuming control therefore gets its OWN row, so request ordering in the
 * requestor job is never load-bearing — reorder the probes freely and nothing
 * breaks:
 *
 *   media_image_id      → DELETE api/images/{image}                 (consumed)
 *   media_document_id   → DELETE api/documents/{document}           (consumed)
 *   media_content_id    → DELETE api/content/uploads/{upload}       (consumed)
 *   page_delete_id      → DELETE api/site/pages/{page}              (consumed)
 *   item_id             → DELETE api/content/items/{item}           (consumed)
 *   page_id + section_id→ GET  api/site/sections/{section}{,/items,/groups,/trace}
 *   item_pool_id        → DELETE api/content/pools/watch/selection/{item}
 *                         + .../links/{platform} + .../overrides/{facet}/{column}
 *
 * POOL IS NOT COSMETIC. UserDocumentController::destroy runs its pool check
 * BEFORE the ownership check, so a gallery-pool id 404s there for the wrong
 * reason on BOTH identities. ContentController::destroyUpload checks ownership
 * first and pool second, so its probe would be honest with a gallery id but its
 * CONTROL would 404. Hence a documents-pool and a content-pool row each, on both
 * identities. UserGalleryController and UserUploadController do not filter pool.
 *
 * site.site_media carries UNIQUE (site_id, pool, sort_order) WHERE deleted_at IS
 * NULL — the two gallery rows need distinct sort_order; different pools may
 * reuse 0.
 *
 * @return array<string, string>
 */
function seedProbeFixtures(User $user, Site $site, string $label): array
{
    $db = DB::connection('pgsql');
    $now = now();
    $userId = (string) $user->id;
    $siteId = (string) $site->id;

    $id = static fn (): string => (string) Str::uuid();

    // --- SiteMedia, one row per pool-filtered surface. media_type/pool values
    // are CHECK-constrained (baseline migration site_media_pool_check /
    // site_media_media_type_check).
    $mediaImageId = $id();
    $mediaDocumentId = $id();
    $mediaContentId = $id();
    $db->table('site.site_media')->insert([
        [
            'id' => $mediaImageId, 'site_id' => $siteId,
            'path' => "dast/{$label}/gallery-2.jpg", 'pool' => 'gallery',
            'media_type' => 'image', 'sort_order' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ],
        [
            'id' => $mediaDocumentId, 'site_id' => $siteId,
            'path' => "dast/{$label}/document.pdf", 'pool' => 'documents',
            'media_type' => 'document', 'sort_order' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ],
        [
            'id' => $mediaContentId, 'site_id' => $siteId,
            'path' => "dast/{$label}/content-1.jpg", 'pool' => 'content',
            'media_type' => 'image', 'sort_order' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ],
    ]);

    // --- Services + categories, on the content lane (services cutover, spec §28).
    // site.services / site.service_categories were DROPPED 2026-08-18; seeding
    // them threw 42P01 and took the whole lane down before ZAP started.
    //
    // Re-pointing the insert is NOT enough on its own. UserServiceController::show
    // resolves through ManualServiceItems::find(), whose baseQuery INNER JOINs
    // content.source_items -> content.sources and filters cs.kind='manual' — so a
    // bare content.items row is invisible to it and would 404 for "unaddressable"
    // rather than for wrong-owner. That is the vacuous-pass class this lane exists
    // to eliminate (and its paired CONTROL would 404 too, failing the run 12
    // minutes in). All three rows are therefore required, not decorative.
    //
    // Shapes are the real ones ProjectionWriter::writeManualItem() produces,
    // checked against live dev rows: label 'manual', priority 200
    // (ProjectionWriter::MANUAL_SOURCE_PRIORITY), coord 'manual:<item uuid>',
    // projector_version 0 (= no projector governs this row).
    //
    // idx_content_sources_manual is UNIQUE on user_id WHERE kind='manual', so
    // this is the ONE manual source for this identity — reuse $manualSourceId for
    // any future manual-lane fixture rather than inserting a second.
    $manualSourceId = $id();
    $db->table('content.sources')->insert([
        'id' => $manualSourceId, 'user_id' => $userId, 'kind' => 'manual',
        'connection_id' => null, 'label' => 'manual', 'priority' => 200,
        'created_at' => $now, 'updated_at' => $now,
    ]);

    // kind='service' is load-bearing: ManualServiceItems::baseQuery filters
    // i.kind='service', so any other kind 404s for the wrong reason.
    $serviceId = $id();
    $db->table('content.items')->insert([
        'id' => $serviceId, 'user_id' => $userId, 'kind' => 'service',
        'headline_cache' => "DAST Service {$label}",
        'created_at' => $now, 'updated_at' => $now,
    ]);

    $db->table('content.source_items')->insert([
        'id' => $id(), 'source_id' => $manualSourceId, 'item_id' => $serviceId,
        'coord' => "manual:{$serviceId}", 'kind' => 'service',
        'projector_version' => 0, 'removed_at' => null,
    ]);

    // ServiceCollections::find filters kind='service_category' on
    // content.collections. is_user_created=true + external_ref=null is the
    // user-created shape (rule 3) — external_ref is the machine natural key and
    // is UNIQUE per (user, kind, external_ref), so a human-created row leaves it
    // null.
    $categoryId = $id();
    $db->table('content.collections')->insert([
        'id' => $categoryId, 'user_id' => $userId, 'parent_id' => null,
        'label' => "DAST Category {$label}", 'kind' => 'service_category',
        'external_ref' => null, 'removed_at' => null, 'position' => 0,
        'is_user_created' => true,
        'created_at' => $now, 'updated_at' => $now,
    ]);

    // --- Pages + a section. `key` is UNIQUE per site; `kind` is CHECK-constrained.
    // Two pages: one parent for the section (its four GET probes are
    // non-destructive), one for the DELETE control to consume — deleting the
    // parent would CASCADE the section out from under the other four.
    $pageId = $id();
    $pageDeleteId = $id();
    $db->table('site.pages')->insert([
        [
            'id' => $pageId, 'site_id' => $siteId, 'key' => 'dast-probe',
            'label' => 'DAST Probe', 'created_at' => $now, 'updated_at' => $now,
        ],
        [
            'id' => $pageDeleteId, 'site_id' => $siteId, 'key' => 'dast-delete',
            'label' => 'DAST Delete', 'created_at' => $now, 'updated_at' => $now,
        ],
    ]);

    $sectionId = $id();
    $db->table('site.sections')->insert([
        'id' => $sectionId, 'page_id' => $pageId, 'site_id' => $siteId,
        'kind' => 'richtext', 'label' => "DAST Section {$label}",
        'created_at' => $now, 'updated_at' => $now,
    ]);

    // --- Feedback. kind + message length are CHECK-constrained.
    $feedbackId = $id();
    $db->table('core.feedback')->insert([
        'id' => $feedbackId, 'user_id' => $userId, 'kind' => 'other',
        'message' => "Seeded by active/seed-identities.php for identity {$label}.",
        'created_at' => $now, 'updated_at' => $now,
    ]);

    // --- Restyle. An empty snapshot is safe: DesignKitRestyleService::undo does
    // updateOrInsert([...$snapshot, 'updated_at' => now()]) against site.design_kits,
    // which the site-create trigger already provisioned.
    $restyleId = $id();
    $db->table('site.design_kit_restyles')->insert([
        'id' => $restyleId, 'site_id' => $siteId, 'snapshot' => json_encode([]),
        'applied_at' => $now,
    ]);

    // --- Notification. user_id MUST be non-null: NotificationPolicy::view returns
    // true unconditionally for global (user_id IS NULL) notifications, so a
    // null-user fixture would make the probe pass without proving anything.
    // `type` is CHECK-constrained.
    $notificationId = $id();
    $db->table('notifications.notifications')->insert([
        'id' => $notificationId, 'user_id' => $userId, 'type' => 'Info',
        'title' => "DAST Notification {$label}", 'body' => 'Seeded fixture.',
        'created_at' => $now, 'updated_at' => $now,
    ]);

    // --- Content items. kind='video' is deliberate: PoolRegistry::POOLS maps
    // 'watch' => ['video'], and PoolController::findPoolItem filters on
    // kind IN kinds($pool) — an item of the wrong kind 404s for the wrong reason.
    $itemId = $id();
    $itemPoolId = $id();
    $db->table('content.items')->insert([
        [
            'id' => $itemId, 'user_id' => $userId, 'kind' => 'video',
            'headline_cache' => "DAST Item {$label}",
            'created_at' => $now, 'updated_at' => $now,
        ],
        [
            'id' => $itemPoolId, 'user_id' => $userId, 'kind' => 'video',
            'headline_cache' => "DAST Pool Item {$label}",
            'created_at' => $now, 'updated_at' => $now,
        ],
    ]);

    // Both ItemLinkController::destroy and ManualOverrideController::destroy
    // resolve the item OWNER-SCOPED first (findItem), so their PROBES 404 on
    // ownership and never reach the "child row absent" branch — the ordering is
    // already the safe way round. These rows exist purely so the CONTROLS return
    // 200 instead of 404-for-no-such-link, which would be a vacuous control.
    //
    // facet/column are not validated by either destroy(), but use the REAL pair
    // (f_text.headline — see App\Content\Values\ValueResolver) rather than an
    // invented one: a fixture that no other code path can see is a trap for the
    // next person who reuses it.
    $db->table('content.item_links')->insert([
        'id' => $id(), 'item_id' => $itemPoolId, 'platform' => 'custom',
        'url' => 'https://dast.example.invalid/link',
        'created_at' => $now, 'updated_at' => $now,
    ]);

    $db->table('content.manual_overrides')->insert([
        'id' => $id(), 'item_id' => $itemPoolId, 'facet' => 'f_text',
        'column_name' => 'headline', 'value' => json_encode('DAST override'),
        'created_at' => $now, 'updated_at' => $now,
    ]);

    // --- Routing connection. RAW, and that is load-bearing:
    // IntegrationConnectionObserver::saved gates on
    // wasRecentlyCreated || wasChanged(payload|display_settings|is_active) and
    // then folds through IdentitySync for google-business. An Eloquent create
    // would satisfy wasRecentlyCreated and fire PlatformRefresher. Raw-inserted,
    // no observer runs; and ConnectionsController::setPrimary changes only
    // is_primary, which satisfies none of those gates — so the CONTROL fires no
    // outbound request either.
    //
    // `platform` is a GENERATED ALWAYS ... STORED column (migration
    // 20260727110004) — writing it raises an error. Write surface_key instead.
    // partna.custom_link is deliberately is_connectable=false and non-Google.
    $connectionId = $id();
    $db->table('site.platform_connections')->insert([
        'id' => $connectionId, 'user_id' => $userId,
        'surface_key' => 'partna.custom_link', 'routing_class' => 'link',
        'resource_id' => "https://dast.example.invalid/{$label}",
        'payload' => json_encode([]), 'is_active' => true, 'is_primary' => false,
        'created_at' => $now, 'updated_at' => $now,
    ]);

    // --- Routing suggestion. No Eloquent model exists; columns and their CHECK
    // constraints come from supabase/migrations/20260727120000_routing_schema.sql.
    // state must be 'proposed' or 'blocked' — SuggestionsController::findIntent
    // filters on those two, so any other value 404s for the wrong reason.
    $intentId = $id();
    $db->table('routing.source_intents')->insert([
        'id' => $intentId, 'user_id' => $userId,
        'surface_key' => 'partna.custom_link', 'routing_class' => 'link',
        'identifier' => "https://dast.example.invalid/intent-{$label}",
        'state' => 'proposed', 'origin' => 'paste',
        'created_at' => $now, 'updated_at' => $now,
    ]);

    // --- Platform-surface fixtures for the api/platforms/* IDOR probes.
    //
    // api/platforms/* is excluded from the ZAP SCAN (zap-context.yaml) because its
    // handlers make real, paid third-party calls. That exclusion is correct for
    // FUZZING and stays. It was also blocking AUTHORIZATION probing, which costs
    // one request — hence these rows. Each of the five deciders below was verified
    // from source to fire no outbound call on EITHER the 200 or the 404 path:
    //
    //   * every remover resolves via connectionsFor() -> ->where('user_id'), then
    //     forgetConnection() -> policy 'delete' -> $connection->delete();
    //   * IntegrationConnectionObserver::deleted() calls
    //     IntegrationConnectionCacheRefresher::refresh(), which dispatches
    //     CloudflareCachePurgeJob -> CloudflarePurgeService, and that no-ops
    //     silently when unconfigured (:100) — .env.example leaves
    //     CLOUDFLARE_ZONE_ID/CLOUDFLARE_CACHE_PURGE_TOKEN empty and .env.dast
    //     inherits that, with APP_ENV=local so it takes the no-op branch, not the
    //     production throw;
    //   * that same hook calls $connection->user?->site?->touch() ONLY for
    //     hasCompletenessPredicate() platforms, and exactly two opt in — fresha and
    //     shop (PlatformRegistryServiceProvider:365,479). None of the five surfaces
    //     here is one, so none reaches SiteObserver:76 -> SyncSubdomainToKvJob (a
    //     real Cloudflare KV write). That is WHY shop is not probed.
    //
    // RAW inserts, same reasoning as the routing connection above: an Eloquent
    // create would set wasRecentlyCreated and fire the observer's saved() path.
    // `platform` is GENERATED ALWAYS ... STORED from surface_key — never write it.
    // surface_key/routing_class values are LegacyPlatformMap's, not invented.
    //
    // EVERY DESTRUCTIVE CONTROL GETS ITS OWN ROW, so requestor ordering stays
    // non-load-bearing — the four DELETE controls below each consume one row, and
    // reusing the routing CONNECTION_ID row (also partna.custom_link) would make
    // routing-primary's control depend on running before custom-links'.
    $platformConnection = function (
        string $surfaceKey,
        string $routingClass,
        ?string $resourceKind,
        string $resourceId,
        array $payload,
    ) use ($db, $id, $userId, $now): string {
        $rowId = $id();
        $db->table('site.platform_connections')->insert([
            'id' => $rowId, 'user_id' => $userId,
            'surface_key' => $surfaceKey, 'routing_class' => $routingClass,
            'resource_kind' => $resourceKind, 'resource_id' => $resourceId,
            'payload' => json_encode($payload), 'is_active' => true, 'is_primary' => false,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return $rowId;
    };

    // GenericPlatformController::removeAccount. accountRows() keeps rows whose
    // resource_kind is NEITHER 'event' NOR 'link', so an account row is NULL —
    // the CHECK constraint allows exactly (NULL | 'event' | 'link').
    $platformAccountId = $id();
    $platformConnection('spotify.player', 'content', null, $platformAccountId, [
        'url' => "https://open.spotify.com/artist/{$label}",
    ]);

    // EventsPlatformController::removeAccount — a DIFFERENT decider from the one
    // above despite the same verb: it takes the connection lock BEFORE the lookup
    // (:195) where GenericPlatformController looks up first (:299).
    $platformEventsAccountId = $id();
    $platformConnection('eventbrite.organiser', 'events', null, $platformEventsAccountId, [
        'url' => "https://www.eventbrite.com/o/{$label}",
        'organiser' => 'DAST Organiser',
        'upcoming' => [],
        'hidden_event_ids' => [],
    ]);

    // EventsPlatformController::removeEvent. It looks for resource_id
    // 'event-'.$id among eventRows() FIRST, so the row is stored prefixed and the
    // probe URL carries the bare id.
    $platformEventId = $id();
    $platformConnection('eventbrite.organiser', 'events', 'event', 'event-'.$platformEventId, [
        'url' => "https://www.eventbrite.com/e/{$label}",
        'title' => 'DAST Event',
    ]);

    // CustomLinksController::removeLink, on the content lane. It no longer reads
    // site.platform_connections at all: it goes through LinkPoolReader::remove ->
    // owns() -> cards(), which reads site.section_items JOIN content.items with
    // i.kind='link' and si.state='pinned'. A partna.custom_link connection row is
    // therefore unaddressable by it — the control 404'd, which is how this was
    // caught. (Unlike the services fixture above this one never THREW: the
    // connections table still exists, so the fixture just went quietly vacuous.
    // Only the paired control's 200 assertion could see it.)
    //
    // The section is provisioned through the REAL PoolSectionProvisioner rather
    // than hand-rolled: its row carries rule/mode/order_by from
    // PoolRegistry::sectionShape(), and a second copy of that shape here is
    // exactly the drift this lane exists to catch. It is pure DB::table inserts,
    // no Eloquent and no jobs, so it is safe under QUEUE_CONNECTION=sync.
    //
    // LinkPoolWriter::add() would build all of this for us, and is deliberately
    // NOT used: it ends in SiteCacheLanes::bust(), which dispatches
    // CloudflareCachePurgeJob — inline under sync, and CloudflareCachePurgeJob
    // has no config guard before it resolves the purge service. That is an
    // outbound call from a seeder whose containment guards exist because one
    // reached a remote host on 2026-08-07. Raw rows, same discipline as above.
    $linkSection = app(PoolSectionProvisioner::class)->ensure($site, LinkPoolWriter::POOL);

    $linkUrl = "https://dast.example.invalid/link-{$label}";
    $platformLinkId = $id();
    $db->table('content.items')->insert([
        'id' => $platformLinkId, 'user_id' => $userId, 'kind' => 'link',
        'headline_cache' => 'DAST Link',
        'created_at' => $now, 'updated_at' => $now,
    ]);

    // Same manual source as the service fixture — idx_content_sources_manual is
    // UNIQUE on user_id WHERE kind='manual', so there is exactly one per identity.
    // coord matches LinkPoolWriter::coordFor() so a later real add() through the
    // app is idempotent against this row rather than minting a duplicate.
    $db->table('content.source_items')->insert([
        'id' => $id(), 'source_id' => $manualSourceId, 'item_id' => $platformLinkId,
        'coord' => 'manual:'.sha1(strtolower(trim($linkUrl))), 'kind' => 'link',
        'projector_version' => 0, 'removed_at' => null,
    ]);

    // cards() LEFT JOINs f_link for the url — optional for resolution, but a link
    // card with no url is not a link, and the CONTROL's 200 response body carries
    // the remaining links.
    $db->table('content.f_link')->insert([
        'item_id' => $platformLinkId, 'source_id' => $manualSourceId,
        'url' => $linkUrl, 'updated_at' => $now,
    ]);

    // state='pinned' is the load-bearing half: cardsInSection filters on it, and
    // section_items is UNIQUE (section_id, item_id) across BOTH states.
    $db->table('site.section_items')->insert([
        'id' => $id(), 'section_id' => (string) $linkSection->id,
        'item_id' => $platformLinkId, 'state' => 'pinned',
        'sort_key' => 1, 'created_at' => $now,
    ]);

    // EventsCatalog::removeCustom (via EventsController). Its own owner-scoped
    // query — ->where('user_id')->where('platform', 'events-custom')
    // ->where('resource_id', 'event-'.$id) (EventsCatalog:287-291) — NOT
    // forgetConnection, so it is a distinct decider.
    $platformCustomEventId = $id();
    $platformConnection('partna.manual_event', 'events', 'event', 'event-'.$platformCustomEventId, [
        'url' => "https://dast.example.invalid/custom-event-{$label}",
        'title' => 'DAST Custom Event',
    ]);

    return [
        'platform_account_id' => $platformAccountId,
        'platform_events_account_id' => $platformEventsAccountId,
        'platform_event_id' => $platformEventId,
        'platform_link_id' => $platformLinkId,
        'platform_custom_event_id' => $platformCustomEventId,
        'media_image_id' => $mediaImageId,
        'media_document_id' => $mediaDocumentId,
        'media_content_id' => $mediaContentId,
        'service_id' => $serviceId,
        'service_category_id' => $categoryId,
        'page_id' => $pageId,
        'page_delete_id' => $pageDeleteId,
        'section_id' => $sectionId,
        'feedback_id' => $feedbackId,
        'restyle_id' => $restyleId,
        'notification_id' => $notificationId,
        'item_id' => $itemId,
        'item_pool_id' => $itemPoolId,
        'connection_id' => $connectionId,
        'intent_id' => $intentId,
    ];
}

/**
 * Tier 2 fixture: a staff identity. Staff-ness is the core.partna_staff row
 * ALONE (reference: staff access model) — the core.users row exists only so
 * LoadCurrentUser can resolve a caller for the non-staff control route the
 * probe hits first (/api/me).
 *
 * core.partna_staff keys on auth_user_id and has NO user_id column — verified
 * against supabase/migrations/20260726000000_baseline_pilot.sql:1000.
 *
 * auth_user_id and status are NOT in User::$fillable (verified 2026-07-30);
 * mass assignment would silently drop them, so both are assigned directly —
 * same discipline as seedIdentity() above.
 */
function seedStaffIdentity(string $label): array
{
    $email = "dast-staff-{$label}@example.invalid";
    $password = 'dast-seed-'.bin2hex(random_bytes(12));
    $authUserId = createAuthUser($email, $password);

    $user = new User([
        'handle' => "dast-staff-{$label}",
        'handle_lc' => "dast-staff-{$label}",
        'display_name' => "DAST Staff {$label}",
        'first_name' => 'DAST',
        'last_name' => 'STAFF',
        'account_type' => 'partna',
        'primary_email' => $email,
    ]);
    $user->auth_user_id = $authUserId;
    $user->save();

    DB::connection('pgsql')->table('core.partna_staff')->insert([
        'id' => (string) Str::uuid(),
        'auth_user_id' => $authUserId,
        'role' => 'admin',
        'primary_email' => $email,
        'name' => "DAST Staff {$label}",
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'email' => $email,
        'password' => $password,
        'auth_user_id' => $authUserId,
        'user_id' => $user->id,
        'role' => 'admin',
    ];
}

/**
 * Tier 2 fixture: a pool of claimants. A claimant is an auth user with NO
 * core.users row — ClaimSiteService::claim() throws ACCOUNT_EXISTS the moment
 * User::where('auth_user_id', $uid)->exists() is true, so seedIdentity() is
 * deliberately NOT reused here.
 *
 * Distinct uids also keep each claimant in its own throttle:claim bucket (the
 * limiter keys on 'claim:'.$supabase_uid — AppServiceProvider.php:754), which
 * is what lets N of them run concurrently without 429s.
 *
 * @return list<array{email: string, password: string, auth_user_id: string}>
 */
function seedClaimants(int $count): array
{
    $out = [];
    for ($i = 0; $i < $count; $i++) {
        $email = "dast-claimant-{$i}@example.invalid";
        $password = 'dast-seed-'.bin2hex(random_bytes(12));
        $out[] = [
            'email' => $email,
            'password' => $password,
            'auth_user_id' => createAuthUser($email, $password),
        ];
    }

    return $out;
}

/**
 * Tier 2 fixture: one unclaimed, published, FIRST-COME build.
 *
 * contact_email is left NULL on purpose — that is the branch in
 * ClaimSiteService::claim() which skips the email gate and makes the row
 * claimable by any verified email. With the gate active only one email could
 * ever win and the race would be untestable.
 *
 * status='unclaimed' is assigned directly (not fillable) because
 * claim() rejects anything else with ALREADY_CLAIMED before the race even
 * starts. site.sites and core.pre_account_builds are written with raw inserts
 * so no observer fires: SiteObserver dispatches Cloudflare purge / KV sync
 * jobs, and QUEUE_CONNECTION=sync in .env.dast would run them inline.
 *
 * source_type / built_via / build_state values are constrained by CHECKs in
 * the baseline migration (lines 1036-1038).
 */
function seedFirstComeBuild(string $label): array
{
    $subdomain = "dast-firstcome-{$label}";

    $user = new User([
        'handle' => $subdomain,
        'handle_lc' => $subdomain,
        'display_name' => "DAST FirstCome {$label}",
        'first_name' => 'DAST',
        'last_name' => 'FIRSTCOME',
        'account_type' => 'partna',
    ]);
    $user->auth_user_id = null;
    $user->primary_email = null;
    $user->status = 'unclaimed';
    $user->save();

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'subdomain' => $subdomain,
        'is_published' => true,
        'settings' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $buildId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.pre_account_builds')->insert([
        'id' => $buildId,
        'user_id' => $user->id,
        'source_type' => 'instagram',
        'source_ref' => $subdomain,
        'source_ref_lc' => $subdomain,
        'built_via' => 'signup',
        'build_state' => 'ready',
        'contact_email' => null,     // ← first-come. Do not set.
        'auto_invite' => false,      // no outreach from a test fixture
        'expires_at' => now()->addDays(30),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['subdomain' => $subdomain, 'user_id' => $user->id, 'build_id' => $buildId];
}

// CLAIM_RACE_N = 8 — enough contention to make a lock failure obvious without
// straining a laptop.
$identities = [
    'A' => seedIdentity('a'),
    'B' => seedIdentity('b'),
    'staff' => seedStaffIdentity('a'),
    'claimants' => seedClaimants(8),
    'firstComeBuild' => seedFirstComeBuild('a'),
];

file_put_contents("$outdir/identities.json", json_encode($identities, JSON_PRETTY_PRINT));
fwrite(STDERR, "[dast] seed-identities: wrote $outdir/identities.json (users {$identities['A']['id']}, {$identities['B']['id']})\n");
