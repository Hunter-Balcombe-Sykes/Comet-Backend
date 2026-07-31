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
            // handle_lc is deliberately ABSENT: User::setHandleAttribute derives
            // it. Seeding it here would defeat the mutator on the exact call that
            // motivated it — array_merge keeps the definition's key ORDER, so a
            // `create(['handle' => 'x'])` override fills handle first and this
            // stale value second, clobbering the derived one. A caller that wants
            // a deliberately desynced row can still pass handle_lc explicitly;
            // being absent from the definition, it appends last and wins.
            'display_name' => "{$first} {$last}",
            'first_name' => $first,
            'last_name' => $last,
            'primary_email' => fake()->unique()->safeEmail(),
            'country_code' => 'AU',
            'timezone' => 'Australia/Sydney',
            'account_type' => 'partna',
            'status' => 'active',
            'onboarding_step' => 0,
        ];
    }
}
