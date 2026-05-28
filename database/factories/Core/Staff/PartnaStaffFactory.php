<?php

namespace Database\Factories\Core\Staff;

use App\Models\Core\Staff\PartnaStaff;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PartnaStaff>
 */
class PartnaStaffFactory extends Factory
{
    protected $model = PartnaStaff::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'auth_user_id' => (string) Str::uuid(),
            'role' => PartnaStaff::ROLE_SUPPORT,
            'primary_email' => 'staff-' . strtolower(Str::random(8)) . '@partna.au',
            'name' => fake()->name(),
            'phone' => fake()->e164PhoneNumber(),
        ];
    }

    public function admin(): self
    {
        return $this->state(fn () => ['role' => PartnaStaff::ROLE_ADMIN]);
    }
}
