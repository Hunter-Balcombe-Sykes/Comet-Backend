<?php

namespace Database\Factories\Moderation;

use App\Models\Moderation\CsamQuarantine;
use App\Models\Moderation\ModerationCase;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CsamQuarantineFactory extends Factory
{
    protected $model = CsamQuarantine::class;

    public function definition(): array
    {
        return [
            'id'                       => Str::uuid()->toString(),
            // csam_match case; CaseFactory::csamMatch() sets the correct case_type/severity.
            'case_id'                  => ModerationCase::factory()->csamMatch(),
            // site_media_id is a bare UUID — SQLite doesn't enforce FK constraints,
            // and PostgreSQL integration tests create real SiteMedia rows separately.
            'site_media_id'            => Str::uuid()->toString(),
            'content_hash'             => hash('sha256', Str::uuid()->toString()),
            'cloudflare_match_payload' => ['matched_against' => 'NCMEC-NetClean'],
            'r2_quarantine_key'        => 'quarantine/'.Str::uuid()->toString().'.jpg',
            'r2_binary_deleted'        => false,
            'preservation_expires_at'  => now()->addDays(90),
        ];
    }

    /** Simulate a record whose legal-hold window has expired. */
    public function expired(): self
    {
        return $this->state(fn () => ['preservation_expires_at' => now()->subDay()]);
    }

    /** Simulate a record whose R2 binary has already been deleted. */
    public function binaryDeleted(): self
    {
        return $this->state(fn () => [
            'r2_binary_deleted'    => true,
            'r2_binary_deleted_at' => now()->subDay(),
        ]);
    }
}
