<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupFeedbackTable();
});

function seedAgedFeedbackRow(string $createdAt, string $kind = 'bug'): string
{
    $id = (string) Str::uuid();

    DB::connection('pgsql')->table('core.feedback')->insert([
        'id' => $id,
        'user_id' => (string) Str::uuid(),
        'kind' => $kind,
        'message' => 'Some feedback message.',
        'status' => 'new',
        'source' => 'dashboard',
        'internal_notes' => '[]',
        'tags' => '[]',
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    return $id;
}

it('defaults the retention window to 90 days and the prune batch size to 1000', function () {
    expect(config('partna.feedback.retention_days'))->toBe(90);
    expect(config('partna.feedback.prune_batch_size'))->toBe(1000);
});

it('deletes rows older than the retention window and keeps rows inside it', function () {
    $old = seedAgedFeedbackRow(now()->subDays(400)->toDateTimeString());
    $recent = seedAgedFeedbackRow(now()->subDays(10)->toDateTimeString());

    $this->artisan('feedback:prune-old-submissions')->assertSuccessful();

    // The half most often skipped: a row still inside the retention window must survive.
    expect(DB::connection('pgsql')->table('core.feedback')->where('id', $old)->exists())->toBeFalse();
    expect(DB::connection('pgsql')->table('core.feedback')->where('id', $recent)->exists())->toBeTrue();
});

it('deletes nothing on a dry run', function () {
    $old = seedAgedFeedbackRow(now()->subDays(400)->toDateTimeString());

    $this->artisan('feedback:prune-old-submissions', ['--dry-run' => true])->assertSuccessful();

    expect(DB::connection('pgsql')->table('core.feedback')->where('id', $old)->exists())->toBeTrue();
});

it('honours the --days override', function () {
    $old = seedAgedFeedbackRow(now()->subDays(20)->toDateTimeString());

    $this->artisan('feedback:prune-old-submissions', ['--days' => 5])->assertSuccessful();

    expect(DB::connection('pgsql')->table('core.feedback')->where('id', $old)->exists())->toBeFalse();
});

it('deletes every eligible row across multiple batches when the batch size is smaller than the eligible set', function () {
    config(['partna.feedback.prune_batch_size' => 2]);

    for ($i = 0; $i < 5; $i++) {
        seedAgedFeedbackRow(now()->subDays(400)->toDateTimeString());
    }

    $this->artisan('feedback:prune-old-submissions')->assertSuccessful();

    // All 5 rows gone even though the batch size (2) is smaller than the eligible
    // set — proves the do/while batching loop terminates correctly.
    expect(DB::connection('pgsql')->table('core.feedback')->count())->toBe(0);
});
