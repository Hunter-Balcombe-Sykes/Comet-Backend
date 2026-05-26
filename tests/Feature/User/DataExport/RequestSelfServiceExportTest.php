<?php

use App\Http\Controllers\Api\User\Account\UserDataExportController;
use App\Http\Requests\Api\User\RequestDataExportRequest;
use App\Jobs\Gdpr\ExportUserDataJob;
use App\Models\Core\Gdpr\DataExportAudit;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\User\DataExport\DataExportTestCase;

beforeEach(function () {
    DataExportTestCase::boot();
    Queue::fake();
});

function seedActivePro(string $id, string $email = 'jane@example.com', string $status = 'active'): User
{
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'handle' => 'jane',
        'handle_lc' => 'jane',
        'display_name' => 'Jane',
        'primary_email' => $email,
        'status' => $status,
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]);

    return User::find($id);
}

function makeSelfServiceRequest(User $professional): RequestDataExportRequest
{
    $request = RequestDataExportRequest::create('/', 'POST');
    $request->attributes->set('professional', $professional);

    return $request;
}

it('returns 202 + audit row on happy path', function () {
    $pro = seedActivePro((string) Str::uuid());
    $controller = app(UserDataExportController::class);

    $response = $controller->store(makeSelfServiceRequest($pro));

    expect($response->getStatusCode())->toBe(202);
    $data = json_decode($response->getContent(), true);
    expect($data['status'])->toBe('queued');
    expect($data['recipient_email'])->toBe('jane@example.com');
    expect(DataExportAudit::where('user_id', $pro->id)->count())->toBe(1);
    Queue::assertPushed(ExportUserDataJob::class);
});

it('returns 409 when an export is in flight inside the 30-min dedup window', function () {
    $pro = seedActivePro((string) Str::uuid());
    DataExportAudit::create([
        'user_id' => $pro->id,
        'professional_handle_snapshot' => 'jane',
        'triggered_by' => 'self',
        'recipient_email' => 'jane@example.com',
        'status' => 'queued',
    ]);

    $controller = app(UserDataExportController::class);
    $response = $controller->store(makeSelfServiceRequest($pro));

    expect($response->getStatusCode())->toBe(409);
    $data = json_decode($response->getContent(), true);
    expect($data)->toHaveKey('existing_export_id');
});

it('returns 422 when professional has no recipient email', function () {
    $pro = seedActivePro((string) Str::uuid());
    DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->update(['primary_email' => null]);

    $controller = app(UserDataExportController::class);
    $response = $controller->store(makeSelfServiceRequest($pro->fresh()));

    expect($response->getStatusCode())->toBe(422);
});

// Note: the pending_deletion exemption is enforced via route middleware withoutMiddleware().
// Here we verify that a pending_deletion professional can dispatch through the service layer.
it('allows export during pending_deletion grace period', function () {
    $pro = seedActivePro((string) Str::uuid(), 'jane@example.com', 'pending_deletion');
    $controller = app(UserDataExportController::class);

    $response = $controller->store(makeSelfServiceRequest($pro));

    expect($response->getStatusCode())->toBe(202);
});
