<?php

namespace Database\Factories\Core\Site;

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    protected $model = Site::class;

    public function definition(): array
    {
        // user_id is set via ->for($user, 'user') in callers (or filled here when standalone).
        $sub = 'site-'.strtolower(Str::random(8));

        return [
            'id' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'subdomain' => $sub,
            'architecture_id' => 'staple',
            'settings' => [],
            'is_published' => true,
        ];
    }
}
