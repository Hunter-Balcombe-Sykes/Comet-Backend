<?php

namespace Database\Factories\Moderation;

use App\Models\Moderation\ModerationCase;
use App\Models\Moderation\Evidence;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class EvidenceFactory extends Factory
{
    protected $model = Evidence::class;

    public function definition(): array
    {
        return [
            'id'            => Str::uuid()->toString(),
            'case_id'       => ModerationCase::factory(),
            'signal_id'     => null,
            'evidence_type' => 'content_snapshot',
            'payload'       => ['placeholder' => true],
            'content_hash'  => hash('sha256', Str::uuid()->toString()),
        ];
    }

    public function forCase(ModerationCase $case): self
    {
        return $this->state(fn () => ['case_id' => $case->id]);
    }

    public function csamMatch(): self
    {
        return $this->state(fn () => [
            'evidence_type' => 'csam_hash_match',
            'payload'       => ['match_database' => 'NCMEC', 'confidence' => 'exact'],
        ]);
    }
}
