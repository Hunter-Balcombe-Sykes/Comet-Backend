<?php

namespace Database\Factories\Moderation;

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\AuditEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AuditEventFactory extends Factory
{
    protected $model = AuditEvent::class;

    public function definition(): array
    {
        return [
            'id' => Str::uuid()->toString(),
            'actor_kind' => 'staff',
            'actor_staff_id' => PartnaStaff::factory(),
            'action' => 'case.viewed',
            'target_type' => null,
            'target_id' => null,
            'payload' => [],
        ];
    }

    public function systemAction(string $action, array $payload = []): self
    {
        return $this->state(fn () => [
            'actor_kind' => 'system',
            'actor_staff_id' => null,
            'action' => $action,
            'payload' => $payload,
        ]);
    }
}
