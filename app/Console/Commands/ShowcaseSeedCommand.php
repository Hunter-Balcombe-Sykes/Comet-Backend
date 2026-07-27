<?php

namespace App\Console\Commands;

use App\Catalog\CompiledCatalog;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Routing\ConnectionPayload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds the two showcase accounts from plan §22.1 — the surface the owner
 * actually reviews before anything ships wider.
 *
 * Two accounts because the capability matrix makes one structurally
 * impossible: a food business cannot hold booking auto-routes, and a partna
 * account cannot hold a menu. Between them they cover every capability,
 * routing class and rule mode.
 *
 * Connections use real public profiles (a real restaurant's Uber Eats page, a
 * real artist's Spotify) — the point is to see the system behave against data
 * we did not author. Dev only, noindex, and every mirrored byte falls under
 * the same attribution/takedown rules as production.
 *
 *   php artisan showcase:seed --dry-run
 *   php artisan showcase:seed
 */
class ShowcaseSeedCommand extends Command
{
    protected $signature = 'showcase:seed
        {--dry-run : print what would be created and change nothing}
        {--only= : creator|eats}';

    protected $description = 'Seed the two showcase accounts (plan §22.1) with one of every platform';

    /**
     * The creator account: everything a solo professional can hold.
     *
     * @var array<string, string> surface key => identifier
     */
    private const CREATOR = [
        'instagram.profile' => 'nasa',
        'tiktok.profile' => 'nasa',
        'youtube.channel' => 'UCLA_DiR1FfKNvjuUpBHmylQ',
        'twitch.channel' => 'monstercat',
        'vimeo.account' => 'vimeo',
        'spotify.player' => 'artist/4gzpq5DPGxSnKTe4SA8HAU',
        'soundcloud.player' => 'monstercat',
        'bandcamp.artist' => 'https://amiinaband.bandcamp.com',
        'apple_music.artist' => '1419227',
        'apple_podcasts.show' => '1200361736',
        'substack.publication' => 'thebrowser',
        'x.profile' => 'nasa',
        'linkedin.profile' => 'nasa',
        'facebook.profile' => 'NASA',
        'threads.profile' => 'nasa',
        'medium.profile' => 'nasa',
        'strava.club' => '1',
        'skool.community' => 'skool-community-1234',
        'fresha.book' => 'some-salon-abc123',
        'partna.custom_link' => 'https://www.nasa.gov/',
    ];

    /**
     * The food business: menus, ordering, reservations, reviews.
     *
     * @var array<string, string>
     */
    private const EATS = [
        'google_business.listing' => 'ChIJN1t_tDeuEmsRUsoyG83frY4',
        'uber_eats.order' => 'https://www.ubereats.com/au/store/some-store',
        'doordash.order' => 'https://www.doordash.com/store/some-store-123456/',
        'menulog.order' => 'https://www.menulog.com.au/restaurants-some-store',
        'square.order' => 'https://some-store.square.site',
        'opentable.reserve' => '12345',
        'resdiary.reserve' => 'some-venue',
        'nowbookit.reserve' => 'acct-1-venue-2',
        'eventbrite.organiser' => 'some-organiser-1234',
        'humanitix.organiser' => 'some-host',
        'shopify.store' => 'somestore',
        'instagram.profile' => 'nasa',
        'partna.custom_link' => 'https://www.example.com/',
    ];

    public function handle(): int
    {
        // NOTE: `sector` must be a real slug from core.users' users_sector_check
        // (e.g. 'musician', 'restaurant') — NOT one of the plan's preset BUCKET
        // names. The SQLite test mirror has no CHECK constraint, so this only
        // fails against real Postgres; getting it wrong once is what put this
        // note here.
        $dryRun = (bool) $this->option('dry-run');
        $only = $this->option('only');

        $plans = array_filter([
            'creator' => $only === 'eats' ? null : ['handle' => 'showcase-creator', 'type' => 'partna', 'sector' => 'musician', 'map' => self::CREATOR],
            'eats' => $only === 'creator' ? null : ['handle' => 'showcase-eats', 'type' => 'business', 'sector' => 'restaurant', 'map' => self::EATS],
        ]);

        foreach ($plans as $label => $plan) {
            $this->seedAccount($label, $plan, $dryRun);
        }

        if ($dryRun) {
            $this->newLine();
            $this->comment('Dry run — nothing was written.');
        }

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $plan */
    private function seedAccount(string $label, array $plan, bool $dryRun): void
    {
        $this->newLine();
        $this->info("── {$plan['handle']} ({$plan['type']}, {$plan['sector']}) ──");

        $unservable = [];
        foreach (array_keys($plan['map']) as $surfaceKey) {
            if (CompiledCatalog::surface($surfaceKey) === null) {
                $unservable[] = $surfaceKey;
            }
        }

        if ($unservable !== []) {
            // A showcase built on surfaces that do not exist proves nothing;
            // say so loudly rather than silently seeding a subset.
            $this->error('Not in the compiled catalog: '.implode(', ', $unservable));
            $this->line('Run `php artisan catalog:compile` first, or fix the surface keys above.');

            return;
        }

        if ($dryRun) {
            foreach ($plan['map'] as $surfaceKey => $identifier) {
                $surface = CompiledCatalog::surface($surfaceKey);
                $this->line(sprintf('  %-28s %-12s %s', $surfaceKey, $surface['routing_class'], $identifier));
            }
            $this->line(sprintf('  = %d connections', count($plan['map'])));

            return;
        }

        $user = $this->ensureUser($plan['handle'], $plan['type'], $plan['sector']);
        $created = 0;
        $skipped = 0;

        foreach ($plan['map'] as $surfaceKey => $identifier) {
            $surface = CompiledCatalog::surface($surfaceKey);

            $existing = IntegrationConnection::query()
                ->where('user_id', $user->id)
                ->where('surface_key', $surfaceKey)
                ->where('resource_id', $identifier)
                ->whereNull('deleted_at')
                ->exists();

            if ($existing) {
                $skipped++;

                continue;
            }

            $connection = new IntegrationConnection([
                'user_id' => $user->id,
                'surface_key' => $surfaceKey,
                'routing_class' => $surface['routing_class'],
                'resource_id' => $identifier,
                'payload' => ConnectionPayload::forWrite(
                    $this->canonicalUrlFor($surface, $identifier),
                    $identifier,
                    (string) ($surface['identifier_kind'] ?? ''),
                    'showcase',
                ),
                'is_active' => true,
                'last_refresh_status' => 'pending',
            ]);
            $connection->save();

            // First connection in an exclusive class owns the CTA, matching
            // what the reconciler would have done for a real user.
            if (in_array($surface['routing_class'], ['booking', 'reservations', 'ordering'], true)) {
                $hasPrimary = IntegrationConnection::query()
                    ->where('user_id', $user->id)
                    ->where('routing_class', $surface['routing_class'])
                    ->where('is_primary', true)
                    ->exists();
                if (! $hasPrimary) {
                    $connection->forceFill(['is_primary' => true])->save();
                }
            }

            $created++;
        }

        $this->line("  created {$created}, already present {$skipped}");
        $this->line("  dashboard: /account · sitepage: https://{$plan['handle']}.partna.au");
    }

    /**
     * The URL a real user would have pasted to create this connection.
     *
     * Seeds are stored as identifiers, not URLs, so the canonical URL is
     * rebuilt from the surface's template. Templates carry either one
     * placeholder (`{handle}`) or a slash-joined run of them (`{kind}/{id}`,
     * whose identifier is itself `artist/xyz`), so a run is substituted whole.
     * Some surfaces have no template and some seeds are already absolute URLs —
     * both fall through to the identifier, which is the truest thing available.
     *
     * @param  array<string, mixed>  $surface
     */
    private function canonicalUrlFor(array $surface, string $identifier): string
    {
        if (str_starts_with($identifier, 'http://') || str_starts_with($identifier, 'https://')) {
            return $identifier;
        }

        $template = $surface['canonical_url_template'] ?? null;

        if (! is_string($template) || $template === '') {
            return $identifier;
        }

        return preg_replace('/\{[a-z_]+\}(?:\/\{[a-z_]+\})*/', $identifier, $template, 1) ?? $identifier;
    }

    private function ensureUser(string $handle, string $accountType, string $sector): User
    {
        $existing = User::query()->where('handle_lc', strtolower($handle))->first();

        if ($existing !== null) {
            return $existing;
        }

        $userId = (string) Str::uuid();

        DB::table('core.users')->insert([
            'id' => $userId,
            'handle' => $handle,
            'handle_lc' => strtolower($handle),
            'display_name' => Str::headline(str_replace('-', ' ', $handle)),
            'first_name' => 'Showcase',
            'primary_email' => "{$handle}@showcase.partna.test",
            'account_type' => $accountType,
            'sector' => $sector,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('site.sites')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'subdomain' => $handle,
            // Published so the sitepage is reviewable, but these are dev-only
            // accounts and the unclaimed/noindex policy applies to the whole
            // environment.
            'is_published' => true,
            'settings' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->line("  created account {$handle}");

        return User::query()->findOrFail($userId);
    }
}
