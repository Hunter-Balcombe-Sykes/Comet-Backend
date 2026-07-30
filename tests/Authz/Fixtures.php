<?php

namespace Tests\Authz;

use App\Models\Core\Feedback;
use App\Models\Core\Notifications\Notification;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Enquiry;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Page;
use App\Models\Core\Site\Section;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\Customer;
use App\Models\Core\User\Service;
use App\Models\Core\User\ServiceCategory;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Identity fixtures for the authorization matrix.
 *
 * Three identities: A (the caller whose token every cross-tenant request
 * carries), B (the victim, owning one row of every substitutable model), and C
 * (an unclaimed provisional user — status='unclaimed', no email, no auth
 * identity).
 *
 * Seeded ONCE per process and committed OUTSIDE the per-test transaction that
 * AuthzTestCase opens. That ordering is load-bearing: seeding inside the
 * transaction would make the fixtures vanish on the first rollback, and every
 * subsequent case would 404 because the row never existed rather than because
 * authorization worked — a green matrix that proved nothing.
 *
 * Rows are left behind after a run. That is intentional and safe: CI uses a
 * throwaway service container, and locally the lane's README says to recreate
 * the container.
 *
 * Column choices below come from the LIVE schema, not from a model's $fillable:
 *
 *   blocks             user_id, site_id
 *   customers          user_id
 *   enquiries          user_id, site_id, name, email, subject, message
 *   service_categories user_id, title
 *   services           user_id, title, price_cents
 *   site_media         site_id, path
 *   sites              user_id, subdomain
 *
 * Attributes are assigned directly rather than mass-assigned: tenancy FKs are
 * deliberately not fillable (project rule), and direct assignment sidesteps
 * every $fillable question at once.
 */
final class Fixtures
{
    private static ?User $a = null;

    private static ?User $b = null;

    private static ?User $c = null;

    /** @var array<string, string> model FQCN => identity B's row id */
    private static array $ids = [];

    public static function ensureSeeded(): void
    {
        if (self::$b !== null) {
            return;
        }

        // Commit the transaction AuthzTestCase opened, seed durably, then
        // reopen — so fixtures survive every later test's rollback while the
        // test itself still runs inside one.
        $connection = DB::connection('pgsql');
        $reopen = $connection->transactionLevel() > 0;

        if ($reopen) {
            $connection->commit();
        }

        try {
            // Locals first, statics last. Assigning self::$b before
            // seedOwnedBy() means a throw mid-seed leaves $b set, the guard
            // above short-circuits every later call, and the ids map stays
            // silently partial — the failure then surfaces as an unrelated
            // "no fixture for X" much later.
            $a = self::makeUser('authz-a');
            $b = self::makeUser('authz-b');
            $c = self::makeUnclaimed('authz-c');

            // Identity A needs a site of its own. Site-scoped controllers
            // resolve the CALLER's site first and return 422 when there isn't
            // one, so an A with no site never reaches the authorization check
            // and every site route scores "inconclusive" rather than testing
            // anything.
            self::giveSite($a, 'authz-attacker');

            self::seedOwnedBy($b);

            self::$a = $a;
            self::$b = $b;
            self::$c = $c;
        } finally {
            if ($reopen) {
                $connection->beginTransaction();
            }
        }
    }

    public static function identityA(): User
    {
        self::ensureSeeded();

        return self::$a;
    }

    public static function identityB(): User
    {
        self::ensureSeeded();

        return self::$b;
    }

    public static function unclaimed(): User
    {
        self::ensureSeeded();

        return self::$c;
    }

    public static function idFor(string $modelFqcn): ?string
    {
        self::ensureSeeded();

        return self::$ids[ltrim($modelFqcn, '\\')] ?? null;
    }

    /** @return array<int, string> */
    public static function seededModels(): array
    {
        self::ensureSeeded();

        return array_keys(self::$ids);
    }

    /**
     * core.users.auth_user_id is a real FK onto the auth.users shim. SQLite
     * never enforced it, so a factory's random uuid is fine there and a 23503
     * here — the parent row has to exist first.
     */
    private static function makeUser(string $handle): User
    {
        if ($existing = User::query()->where('handle_lc', $handle)->first()) {
            return $existing;
        }

        $authUserId = (string) Str::uuid();

        DB::connection('pgsql')->table('auth.users')->insert([
            'id' => $authUserId,
            'email' => $handle.'@authz.test',
        ]);

        return User::factory()->create([
            'auth_user_id' => $authUserId,
            'handle' => $handle,
            'handle_lc' => $handle,
            'primary_email' => $handle.'@authz.test',
            'status' => 'active',
        ]);
    }

    /**
     * Provisional users carry no auth identity and no email at all — the
     * pre-account signup path creates them before anyone has claimed the site.
     */
    private static function makeUnclaimed(string $handle): User
    {
        if ($existing = User::query()->where('handle_lc', $handle)->first()) {
            return $existing;
        }

        return User::factory()->create([
            'auth_user_id' => null,
            'handle' => $handle,
            'handle_lc' => $handle,
            'primary_email' => null,
            'status' => 'unclaimed',
        ]);
    }

    /**
     * Every row is find-or-create, keyed on identity B's ownership.
     *
     * Fixtures are committed and deliberately outlive a run, so a second
     * invocation against the same database must reuse them rather than insert
     * duplicates — otherwise re-running the lane without recreating the
     * container dies on core_users_handle_lc_unique before a single route is
     * fired.
     */
    private static function giveSite(User $user, string $subdomain): Site
    {
        $site = Site::query()->where('user_id', $user->id)->first();

        if (! $site) {
            $site = new Site;
            $site->subdomain = $subdomain;
            $site->user()->associate($user);
            $site->save();
        }

        return $site;
    }

    private static function seedOwnedBy(User $user): void
    {
        $site = self::giveSite($user, 'authz-victim');

        self::remember(Site::class, $site->id);

        $customer = Customer::query()->where('user_id', $user->id)->first();

        if (! $customer) {
            $customer = new Customer;
            $customer->user()->associate($user);
            $customer->save();
        }

        self::remember(Customer::class, $customer->id);

        $enquiry = Enquiry::query()->where('user_id', $user->id)->first();

        if (! $enquiry) {
            $enquiry = new Enquiry;
            $enquiry->name = 'Authz Victim';
            $enquiry->email = 'victim@authz.test';
            $enquiry->subject = 'authz fixture';
            $enquiry->message = 'authz fixture';
            $enquiry->user()->associate($user);
            $enquiry->site()->associate($site);
            $enquiry->save();
        }

        self::remember(Enquiry::class, $enquiry->id);

        $category = ServiceCategory::query()->where('user_id', $user->id)->first();

        if (! $category) {
            $category = new ServiceCategory;
            $category->title = 'Authz Category';
            $category->user()->associate($user);
            $category->save();
        }

        self::remember(ServiceCategory::class, $category->id);

        $service = Service::query()->where('user_id', $user->id)->first();

        if (! $service) {
            $service = new Service;
            $service->title = 'Authz Service';
            $service->price_cents = 1000;
            $service->user()->associate($user);
            $service->save();
        }

        self::remember(Service::class, $service->id);

        $block = Block::query()->where('user_id', $user->id)->first();

        if (! $block) {
            $block = new Block;
            $block->user()->associate($user);
            $block->site()->associate($site);
            $block->save();
        }

        self::remember(Block::class, $block->id);

        $media = SiteMedia::query()->where('site_id', $site->id)->first();

        if (! $media) {
            $media = new SiteMedia;
            $media->path = 'authz/fixture.webp';
            $media->site()->associate($site);
            $media->save();
        }

        self::remember(SiteMedia::class, $media->id);

        // A real connection row matters more than most: ConnectionsController@
        // setPrimary resolves it with an UNSCOPED IntegrationConnection::query()
        // ->find($connectionId). Sentinelling this route with an unknown id
        // would make it 404 trivially and hide exactly the shape this harness
        // exists to catch.
        $connection = IntegrationConnection::query()->where('user_id', $user->id)->first();

        if (! $connection) {
            $connection = new IntegrationConnection;
            $connection->resource_id = 'authz-fixture-resource';
            $connection->surface_key = 'authz';
            $connection->routing_class = 'profile';
            $connection->platform = 'custom';
            $connection->user()->associate($user);
            $connection->save();
        }

        self::remember(IntegrationConnection::class, $connection->id);

        $page = Page::query()->where('site_id', $site->id)->first();

        if (! $page) {
            $page = new Page;
            $page->key = 'authz';
            $page->label = 'Authz';
            $page->site()->associate($site);
            $page->save();
        }

        self::remember(Page::class, $page->id);

        $section = Section::query()->where('site_id', $site->id)->first();

        if (! $section) {
            $section = new Section;
            $section->kind = 'richtext';
            $section->page()->associate($page);
            $section->site()->associate($site);
            $section->save();
        }

        self::remember(Section::class, $section->id);

        $feedback = Feedback::query()->where('user_id', $user->id)->first();

        if (! $feedback) {
            $feedback = new Feedback;
            $feedback->kind = 'bug';
            $feedback->message = 'authz fixture';
            $feedback->user()->associate($user);
            $feedback->save();
        }

        self::remember(Feedback::class, $feedback->id);

        $notification = Notification::query()->where('user_id', $user->id)->first();

        if (! $notification) {
            $notification = new Notification;
            $notification->type = 'Info';
            $notification->title = 'Authz fixture';
            $notification->body = 'authz fixture';
            $notification->user()->associate($user);
            $notification->save();
        }

        self::remember(Notification::class, $notification->id);

        // Identity B is itself the substitutable row for {user} params.
        self::remember(User::class, $user->id);
    }

    private static function remember(string $modelFqcn, string $id): void
    {
        self::$ids[ltrim($modelFqcn, '\\')] = $id;
    }
}
