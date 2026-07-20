<?php

// Item 5: a staff-initiated rename must be attributed to the acting staff member,
// with reason=staff_rename and the request IP/UA, so the handle_change_log audit
// trail can distinguish staff overrides from self-serve renames. We invoke the
// controller directly with a mocked UpdateSiteAction to capture the options it
// forwards — the behaviour under test is the controller's audit plumbing, not the
// action (which already consumes these options) or the staff route guard.

use App\Http\Controllers\Api\Staff\UserSiteManagement\StaffSiteManagementController;
use App\Http\Requests\Api\Staff\UserSite\StaffUpdateSiteRequest;
use App\Models\Core\HandleChangeLog;
use App\Models\Core\Site\Site;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use App\Services\Site\UpdateSiteAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

it('attributes a staff rename to the acting staff with reason, ip and user agent', function () {
    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'old',
        'handle_lc' => 'old',
        'display_name' => 'Pro',
        'primary_email' => 'pro@example.test',
        'status' => 'active',
        'account_type' => 'partna',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $proId,
        'subdomain' => 'newsub',
        'settings' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $pro = User::query()->findOrFail($proId);
    $site = Site::query()->findOrFail($siteId);

    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();

    // Capture the options the controller forwards to the action.
    $captured = null;
    $action = Mockery::mock(UpdateSiteAction::class);
    $action->shouldReceive('execute')
        ->once()
        ->andReturnUsing(function ($professional, $data, $options) use (&$captured, $site) {
            $captured = $options;

            return $site;
        });

    $request = StaffUpdateSiteRequest::create(
        "/api/staff/professionals/{$proId}/site",
        'PATCH',
        ['subdomain' => 'newsub'],
    );
    $request->setContainer(app());
    $request->server->set('REMOTE_ADDR', '203.0.113.7');
    $request->headers->set('User-Agent', 'pest-staff/1.0');
    $request->attributes->set('partna_staff', $staff);
    $request->setValidator(Validator::make(['subdomain' => 'newsub'], ['subdomain' => ['sometimes']]));

    (new StaffSiteManagementController)->update($request, $pro, $action);

    expect($captured['reason'])->toBe(HandleChangeLog::REASON_STAFF_RENAME);
    expect($captured['actor_id'])->toBe($staff->id);
    expect($captured['ip'])->toBe('203.0.113.7');
    expect($captured['user_agent'])->toBe('pest-staff/1.0');
    expect($captured['allow_force_publish'])->toBeTrue();
    expect($captured['allow_subdomain_override'])->toBeTrue();
});
