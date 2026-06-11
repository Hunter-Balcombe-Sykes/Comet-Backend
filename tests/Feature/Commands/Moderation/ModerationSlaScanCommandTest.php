<?php

use App\Models\Moderation\ModerationCase;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    setupAllModerationTables();
    setupUsersTable();
});

it('logs warnings for cases approaching SLA breach', function () {
    Log::spy();
    ModerationCase::factory()->create([
        'status' => 'open',
        'sla_due_at' => now()->addMinutes(60),  // within 2h warning window
    ]);
    ModerationCase::factory()->create([
        'status' => 'open',
        'sla_due_at' => now()->addHours(24),    // not near breach
    ]);

    $this->artisan('moderation:sla-scan')->assertSuccessful();

    Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx = []) => str_contains($msg, 'sla.breach_risk'))->once();
});

it('emits no warnings when no cases are near breach', function () {
    Log::spy();
    ModerationCase::factory()->create(['status' => 'open', 'sla_due_at' => now()->addDays(5)]);

    $this->artisan('moderation:sla-scan')->assertSuccessful();
    Log::shouldNotHaveReceived('warning');
});

it('ignores resolved cases', function () {
    Log::spy();
    ModerationCase::factory()->resolved()->create([
        'sla_due_at' => now()->addMinutes(30),  // would breach, but resolved
    ]);

    $this->artisan('moderation:sla-scan')->assertSuccessful();
    Log::shouldNotHaveReceived('warning');
});
