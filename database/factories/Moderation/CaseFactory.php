<?php

namespace Database\Factories\Moderation;

use App\Models\Core\User\User;
use App\Models\Moderation\ModerationCase;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CaseFactory extends Factory
{
    protected $model = ModerationCase::class;

    public function definition(): array
    {
        return [
            'id' => Str::uuid()->toString(),
            'case_type' => 'content_report',
            'reportable_type' => 'Site',
            'reportable_id' => Str::uuid()->toString(),
            'reportable_owner_user_id' => null,
            'severity' => 2,
            'status' => 'open',
            'signal_count' => 1,
            'auto_actioned' => false,
            'priority' => 5,
        ];
    }

    public function forOwner(User $owner): self
    {
        return $this->state(fn () => ['reportable_owner_user_id' => $owner->id]);
    }

    public function triaged(): self
    {
        return $this->state(fn () => ['status' => 'triaged', 'triaged_at' => now()]);
    }

    public function underReview(): self
    {
        return $this->state(fn () => ['status' => 'under_review']);
    }

    public function resolved(): self
    {
        return $this->state(fn () => ['status' => 'resolved', 'resolved_at' => now()]);
    }

    public function csamMatch(): self
    {
        return $this->state(fn () => [
            'case_type' => 'csam_match',
            'severity' => 5,
            'status' => 'auto_actioned',
            'auto_actioned' => true,
            'priority' => 1,
        ]);
    }
}
