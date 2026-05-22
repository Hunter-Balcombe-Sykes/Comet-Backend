<?php

use App\Jobs\Gdpr\ExportProfessionalDataJob;
use App\Models\Core\Professional\User;
use App\Services\Professional\DataExport\DataExportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Professional\DataExport\DataExportTestCase;

beforeEach(function () {
    DataExportTestCase::boot();
    Queue::fake();
});

function seedProForService(string $id, string $email = 'jane@example.com'): User
{
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'handle' => 'jane',
        'handle_lc' => 'jane',
        'display_name' => 'Jane',
        'primary_email' => $email,
        'status' => 'active',
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]);

    return User::find($id);
}

it('inserts an audit row with status queued and dispatches the job', function () {
    $pro = seedProForService((string) Str::uuid());
    $service = app(DataExportService::class);

    $audit = $service->dispatch($pro, 'self', null, 'professional');

    expect($audit->status)->toBe('queued');
    expect($audit->triggered_by)->toBe('self');
    expect($audit->recipient_email)->toBe('jane@example.com');
    expect($audit->professional_handle_snapshot)->toBe('jane');

    Queue::assertPushed(ExportProfessionalDataJob::class, fn ($j) => $j->auditId === $audit->id);
});

it('throws DataExportInProgressException when an export was queued in the last 30 minutes', function () {
    $pro = seedProForService((string) Str::uuid());
    $service = app(DataExportService::class);

    $service->dispatch($pro, 'self', null, 'professional');

    expect(fn () => $service->dispatch($pro, 'self', null, 'professional'))
        ->toThrow(\App\Exceptions\Gdpr\DataExportInProgressException::class);
});

it('allows a new export after the dedup window passes', function () {
    Carbon::setTestNow('2026-04-25T10:00:00Z');
    $pro = seedProForService((string) Str::uuid());
    $service = app(DataExportService::class);

    $first = $service->dispatch($pro, 'self', null, 'professional');

    // 31 minutes later — past the 30-min window
    Carbon::setTestNow('2026-04-25T10:31:00Z');
    $second = $service->dispatch($pro, 'self', null, 'professional');

    expect($second->id)->not->toBe($first->id);
    Carbon::setTestNow();
});

it('staff dispatch with send_to=staff resolves recipient to the staff email', function () {
    $pro = seedProForService((string) Str::uuid());
    $staffId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.partna_staff')->insert([
        'id' => $staffId,
        'role' => 'admin',
        'primary_email' => 'admin@sidest.io',
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]);

    $service = app(DataExportService::class);
    $audit = $service->dispatch($pro, 'staff', $staffId, 'staff');

    expect($audit->recipient_email)->toBe('admin@sidest.io');
    expect($audit->triggered_by_staff_id)->toBe($staffId);
});

it('staff dispatch with send_to=professional resolves recipient to the professional email', function () {
    $pro = seedProForService((string) Str::uuid(), 'jane@example.com');
    $staffId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.partna_staff')->insert([
        'id' => $staffId,
        'role' => 'support',
        'primary_email' => 'support@sidest.io',
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]);

    $service = app(DataExportService::class);
    $audit = $service->dispatch($pro, 'staff', $staffId, 'professional');

    expect($audit->recipient_email)->toBe('jane@example.com');
});

it('throws NoRecipientEmailException when professional has no recipient email', function () {
    $pro = seedProForService((string) Str::uuid(), '');
    DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->update(['primary_email' => null]);
    $pro->refresh();

    $service = app(DataExportService::class);

    expect(fn () => $service->dispatch($pro, 'self', null, 'professional'))
        ->toThrow(\App\Exceptions\Gdpr\NoRecipientEmailException::class);
});
