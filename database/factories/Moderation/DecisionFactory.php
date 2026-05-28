<?php

namespace Database\Factories\Moderation;

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\ModerationCase;
use App\Models\Moderation\Decision;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class DecisionFactory extends Factory
{
    protected $model = Decision::class;

    public function definition(): array
    {
        return [
            'id'                  => Str::uuid()->toString(),
            'case_id'             => ModerationCase::factory(),
            'decision_type'       => 'dismiss',
            'reason'              => 'Default factory reason — replace in test.',
            'decided_by_staff_id' => PartnaStaff::factory(),
            'decided_by_system'   => false,
            'auto_actioned'       => false,
        ];
    }

    public function forCase(ModerationCase $case): self
    {
        return $this->state(fn () => ['case_id' => $case->id]);
    }

    public function systemAutoActioned(): self
    {
        return $this->state(fn () => [
            'decided_by_staff_id' => null,
            'decided_by_system'   => true,
            'auto_actioned'       => true,
            'decision_type'       => 'suspend_user',
            'reason'              => 'auto_csam_match',
        ]);
    }
}
