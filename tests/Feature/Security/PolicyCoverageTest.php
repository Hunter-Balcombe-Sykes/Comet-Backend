<?php

use App\Models\Analytics\ActionEvent;
use App\Models\Analytics\ItemView;
use App\Models\Analytics\LinkClick;
use App\Models\Analytics\SectionView;
use App\Models\Analytics\SiteVisit;
use App\Models\Content\ManualOverride;
use App\Models\Core\EmailSuppression;
use App\Models\Core\HandleChangeLog;
use App\Models\Core\MediaVariant;
use App\Models\Core\Notifications\SupabaseEmailEvent;
use App\Models\Core\Site\MenuCategory;
use App\Models\Core\Site\MenuItem;
use App\Models\Core\Site\MenuItemPlatform;
use App\Models\Core\Site\MenuPlatformLink;
use App\Models\Core\Site\SectionGroup;
use App\Models\Core\Site\SectionItem;
use App\Models\Core\Site\ShopBrand;
use App\Models\Core\Site\ShopProduct;
use App\Models\Core\Site\UserHandleAlias;
use App\Models\Core\Staff\StaffAuditEntry;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\AuditEvent;
use App\Models\Moderation\CaseSignal;
use App\Models\Moderation\Evidence;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Policy Coverage Sweep
|--------------------------------------------------------------------------
| Every model under app/Models/ must either (a) have a Gate-registered
| Policy, or (b) appear in POLICY_EXEMPT below with a justification.
|
| Adding a new model? Either register a policy in AppServiceProvider::boot
| or add an entry below explaining why this model doesn't need one.
| Untracked models silently allow IDOR — this test prevents that.
*/

const POLICY_EXEMPT = [
    // Catalog & system tables — no tenant ownership; admin-only or read-only.
    MediaVariant::class,           // owned via parent SiteMedia

    // Public ingestion — write-only via public site endpoints; scoped by
    // ResolvesSiteFromRequest at write time. Reads happen via the analytics
    // API, gated by the parent Site policy.
    ItemView::class,
    ActionEvent::class,
    LinkClick::class,
    SectionView::class,
    SiteVisit::class,

    // Append-only audit log for handle/subdomain renames; readable by staff only — no per-row tenant policy.
    HandleChangeLog::class,

    // Handle alias table — read/write access flows through the parent Professional's policy.
    UserHandleAlias::class,

    // Menu child rows — authorized via the parent Menu, never per-row. Reads and
    // the manual-content writes (MenuContentController's category/item CRUD) both
    // resolve them strictly through the caller's own menu (user-scoped queries →
    // 404 for anything not theirs), so there is no direct IDOR surface — the same
    // precedent as ShopBrand/ShopProduct below. Rebuilt wholesale by MenuFetchJob.
    MenuCategory::class,
    MenuItem::class,
    MenuPlatformLink::class,    // per-platform sync state; access flows through parent Menu
    MenuItemPlatform::class,    // per-platform item availability; access flows through parent Menu

    // FOUND-25: children of the shop IntegrationConnection. Authorized via the
    // parent IntegrationConnectionPolicy through ShopController; no direct API
    // surface (same precedent as MenuItem above).
    ShopBrand::class,
    ShopProduct::class,

    // OPS-2: Append-only staff audit log. Never exposed over the API — support
    // queries via SQL only. No tenant ownership; staff actor and target professional
    // are FK metadata, not authorization keys. A Policy class would be meaningless
    // here because there is no controller action to gate.
    StaffAuditEntry::class,

    // Moderation write-only / child models — no user-facing API endpoints;
    // access is via staff tooling and SQL. Parent resources (ModerationCase,
    // Decision) are covered by CasePolicy / DecisionPolicy.
    Evidence::class,        // child of ModerationCase; owned via parent policy
    ActionLogEntry::class,  // append-only audit log; no per-row gate needed
    AuditEvent::class,      // append-only audit log; no per-row gate needed
    CaseSignal::class,      // write-only ingest; read access via parent case

    // WHK-3: internal forensic trail for auth-email webhook outcomes. No user-facing
    // API endpoint; no tenant ownership (keyed on webhook_id, not user_id). Staff read
    // access is enforced by DB-level RLS (FORCE ROW LEVEL SECURITY + staff-only SELECT
    // policy in the migration). A Laravel policy would be meaningless — there is no
    // controller action to gate and the Gate has no authenticated actor for this table.
    SupabaseEmailEvent::class,

    // Curation + override child rows (plan §6/§7). None carries user_id, and
    // none is reachable except through its parent's route — section_items and
    // section_groups via /site/sections/{section}/…, manual_overrides via
    // /content/items/{item}/overrides. The parent is authorised by
    // SectionPolicy / ContentItemPolicy, so a per-row policy would gate
    // nothing that is not already gated. Same precedent as MenuItem above.
    SectionItem::class,
    SectionGroup::class,
    ManualOverride::class,

    // Send-time suppression list (Resend bounce/complaint). Internal system table,
    // no user-facing API endpoint and no tenant ownership (keyed on email_hash,
    // not user_id). Staff read is enforced by DB-level RLS (FORCE ROW LEVEL
    // SECURITY + staff-only SELECT in the migration). A Laravel policy would be
    // meaningless — no controller action to gate, no authenticated actor. Same
    // posture as SupabaseEmailEvent above.
    EmailSuppression::class,
];

it('every tenant-owned model has a registered policy', function () {
    $modelFiles = (new Finder)
        ->files()
        ->in(app_path('Models'))
        ->name('*.php')
        ->notName('BaseModel.php')
        ->notPath('Views') // read-only DB views are not policy-gated
        ->getIterator();

    $missing = [];

    foreach ($modelFiles as $file) {
        $relative = str_replace([app_path(), '/', '.php'], ['App', '\\', ''], $file->getRealPath());
        if (! class_exists($relative)) {
            continue;
        }

        if (in_array($relative, POLICY_EXEMPT, true)) {
            continue;
        }

        $policy = Gate::getPolicyFor($relative);
        if ($policy === null) {
            $missing[] = $relative;
        }
    }

    expect($missing)->toBe([], "Models without a registered Policy:\n  - ".implode("\n  - ", $missing)."\n\nEither register one in AppServiceProvider::boot() or add to POLICY_EXEMPT in this test with a justification.");
});

it('every POLICY_EXEMPT entry resolves to a real model class', function () {
    foreach (POLICY_EXEMPT as $class) {
        expect(class_exists($class))->toBeTrue("POLICY_EXEMPT entry {$class} does not resolve to an existing class.");
    }
});
