<?php

namespace Database\Factories\Core\Staff;

use App\Models\Core\Staff\StaffAuditEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StaffAuditEntry>
 */
class StaffAuditEntryFactory extends Factory
{
    protected $model = StaffAuditEntry::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'staff_id' => (string) Str::uuid(),
            'staff_email_snapshot' => fake()->safeEmail(),
            'impersonator_staff_id' => null,
            'impersonator_email_snapshot' => null,
            'user_id' => (string) Str::uuid(),
            'professional_handle_snapshot' => 'test-brand',
            'route' => 'staff.professionals.update',
            'http_method' => 'PATCH',
            'status_code' => 200,
            'payload_summary' => ['professional' => (string) Str::uuid()],
            'ip_hash' => hash_hmac('sha256', '127.0.0.1', config('app.key')),
            'user_agent' => 'PHPUnit',
            'created_at' => now(),
        ];
    }
}
