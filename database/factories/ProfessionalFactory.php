<?php

namespace Database\Factories;

use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Professional>
 */
class ProfessionalFactory extends Factory
{
    protected $model = Professional::class;

    public function definition(): array
    {
        $first = fake()->firstName();
        $last = fake()->lastName();
        $handle = strtolower($first.$last.fake()->randomNumber(4));

        return [
            'id' => (string) Str::uuid(),
            'auth_user_id' => (string) Str::uuid(),
            'handle' => $handle,
            'handle_lc' => $handle,
            'display_name' => "{$first} {$last}",
            'first_name' => $first,
            'last_name' => $last,
            'primary_email' => fake()->unique()->safeEmail(),
            'country_code' => 'AU',
            'timezone' => 'Australia/Sydney',
            'professional_type' => 'professional',
            'account_type' => 'individual',
            'status' => 'active',
            'onboarding_step' => 0,
        ];
    }
}
