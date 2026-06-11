<?php

namespace Database\Factories\Moderation;

use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\Decision;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ActionLogEntryFactory extends Factory
{
    protected $model = ActionLogEntry::class;

    public function definition(): array
    {
        return [
            'id' => Str::uuid()->toString(),
            'decision_id' => Decision::factory(),
            'action_type' => 'notify_reporter',
            'action_target' => [],
            'status' => 'pending',
            'attempts' => 0,
        ];
    }

    public function forDecision(Decision $d): self
    {
        return $this->state(fn () => ['decision_id' => $d->id]);
    }
}
