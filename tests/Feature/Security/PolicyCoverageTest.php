<?php

use App\Models\Analytics\LinkClick;
use App\Models\Analytics\SectionView;
use App\Models\Analytics\SiteVisit;
use App\Models\Core\HandleChangeLog;
use App\Models\Core\MediaVariant;
use App\Models\Core\Site\MenuCategory;
use App\Models\Core\Site\MenuItem;
use App\Models\Core\Site\UserHandleAlias;
use App\Models\Core\Staff\StaffAuditEntry;
use App\Models\Core\Waitlist\WaitlistSignup;
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
    WaitlistSignup::class, // public submission, no actor

    // Public ingestion — write-only via public site endpoints; scoped by
    // ResolvesSiteFromRequest at write time. Reads happen via the analytics
    // API, gated by the parent Site policy.
    LinkClick::class,
    SectionView::class,
    SiteVisit::class,

    // Append-only audit log for handle/subdomain renames; readable by staff only — no per-row tenant policy.
    HandleChangeLog::class,

    // Handle alias table — read/write access flows through the parent Professional's policy.
    UserHandleAlias::class,

    // Menu child rows — never authorized directly. They are read only via the
    // parent Menu (gated by SitePolicy + user-scoped in MenuController) and
    // rebuilt wholesale by MenuFetchJob. No per-row API surface to gate.
    MenuCategory::class,
    MenuItem::class,

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
