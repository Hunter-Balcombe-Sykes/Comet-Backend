<?php

namespace Database\Factories;

use App\Models\Core\User\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

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
            'account_type' => 'individual',
            'status' => 'active',
            'onboarding_step' => 0,
        ];
    }
}
