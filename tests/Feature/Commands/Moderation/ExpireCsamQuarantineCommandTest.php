<?php

use App\Models\Moderation\CsamQuarantine;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    setupAllModerationTables();
    setupUsersTable();
    Storage::fake('r2_quarantine');
});

it('deletes R2 binary + flips r2_binary_deleted for expired rows', function () {
    $expired = CsamQuarantine::factory()->expired()->create();
    Storage::disk('r2_quarantine')->put($expired->r2_quarantine_key, 'binary');

    $fresh = CsamQuarantine::factory()->create(); // preservation_expires_at defaults to +90d in factory
    Storage::disk('r2_quarantine')->put($fresh->r2_quarantine_key, 'binary');

    $this->artisan('moderation:expire-csam-quarantine')->assertSuccessful();

    $expired->refresh();
    expect($expired->r2_binary_deleted)->toBeTrue();
    expect($expired->r2_binary_deleted_at)->not->toBeNull();
    expect(Storage::disk('r2_quarantine')->exists($expired->r2_quarantine_key))->toBeFalse();

    $fresh->refresh();
    expect($fresh->r2_binary_deleted)->toBeFalse();
    expect(Storage::disk('r2_quarantine')->exists($fresh->r2_quarantine_key))->toBeTrue();
});

it('skips rows whose binary is already deleted', function () {
    $row = CsamQuarantine::factory()->expired()->binaryDeleted()->create();

    $this->artisan('moderation:expire-csam-quarantine')->assertSuccessful();

    expect($row->fresh()->r2_binary_deleted)->toBeTrue();
});
