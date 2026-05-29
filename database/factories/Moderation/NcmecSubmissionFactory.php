<?php

namespace Database\Factories\Moderation;

use App\Models\Moderation\CsamQuarantine;
use App\Models\Moderation\NcmecSubmission;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class NcmecSubmissionFactory extends Factory
{
    protected $model = NcmecSubmission::class;

    public function definition(): array
    {
        return [
            'id'                 => Str::uuid()->toString(),
            'csam_quarantine_id' => CsamQuarantine::factory(),
            // Minimal placeholder payload — real submissions include media hash,
            // file metadata, and incident details built by the submission service.
            'payload'            => ['mediaHash' => 'placeholder'],
            'status'             => 'pending',
            'attempts'           => 0,
        ];
    }

    /** Pin this submission to an existing CsamQuarantine row. */
    public function forQuarantine(CsamQuarantine $q): self
    {
        return $this->state(fn () => ['csam_quarantine_id' => $q->id]);
    }

    /** Simulate a successfully received and acknowledged NCMEC submission. */
    public function submitted(string $tipId = 'TIP-001'): self
    {
        return $this->state(fn () => [
            'status'               => 'submitted',
            'ncmec_tip_id'         => $tipId,
            'submitted_at'         => now(),
            'response_received_at' => now(),
        ]);
    }

    /** Simulate a failed submission after $attempts attempts. */
    public function failed(int $attempts = 1): self
    {
        return $this->state(fn () => [
            'status'     => 'failed',
            'attempts'   => $attempts,
            'last_error' => 'simulated failure',
        ]);
    }
}
