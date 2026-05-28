<?php

namespace Database\Factories\Moderation;

use App\Models\Moderation\ModerationCase;
use App\Models\Moderation\CaseSignal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CaseSignalFactory extends Factory
{
    protected $model = CaseSignal::class;

    public function definition(): array
    {
        return [
            'id'               => Str::uuid()->toString(),
            'case_id'          => ModerationCase::factory(),
            'signal_source'    => 'content_report',
            'signal_data'      => [],
            'reporter_user_id' => null,
            'reporter_email'   => null,
            'reporter_ip_hash' => hash('sha256', '127.0.0.1:salt'),
            'reason_code'      => 'spam',
            'reason_details'   => null,
            'dedup_hash'       => hash('sha256', Str::uuid()->toString()),
        ];
    }

    public function forCase(ModerationCase $case): self
    {
        return $this->state(fn () => ['case_id' => $case->id]);
    }

    public function fromCsamScan(): self
    {
        return $this->state(fn () => [
            'signal_source' => 'csam_scan',
            'reason_code'   => 'auto_csam_hash_match',
        ]);
    }
}
