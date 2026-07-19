<?php

namespace Database\Factories\Core\User;

use App\Models\Core\User\PreAccountBuild;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PreAccountBuild>
 */
class PreAccountBuildFactory extends Factory
{
    protected $model = PreAccountBuild::class;

    public function definition(): array
    {
        $ref = $this->faker->userName();

        return [
            'source_type' => 'instagram',
            'source_ref' => $ref,
            'source_ref_lc' => mb_strtolower($ref),
            'built_via' => PreAccountBuild::VIA_SIGNUP,
            'build_state' => PreAccountBuild::STATE_PENDING,
            'expires_at' => now()->addDays(30),
        ];
    }
}
