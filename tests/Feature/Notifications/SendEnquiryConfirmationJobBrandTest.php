<?php

use App\Jobs\Notifications\SendEnquiryConfirmationJob;
use App\Mail\Branding\EmailBrand;
use App\Mail\Branding\ProEmailBrandResolver;
use App\Mail\EnquiryConfirmationMail;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

// Requires the contact inbox schema (which creates site.enquiries with all
// columns including confirmation_sent_at) plus the design kit / brand tables
// that ProEmailBrandResolver reads.
beforeEach(function () {
    Cache::flush();
    setupUsersTable();
    setupContactInboxSchema(); // creates site.enquiries, site.blocks, etc.
    setupSitesTable();
    setupDesignKitsTable();
    setupMediaTables();
    setupSubdomainAliasesTable();
});

// ---------------------------------------------------------------------------
// Helper: create a user+site with a seeded design_kits row and a confirmable
// enquiry, then return [$user, $site, $enquiryId].
// ---------------------------------------------------------------------------
function makeConfirmableBrandEnquiry(array $enquiryOverrides = []): array
{
    $user = User::factory()->create(['display_name' => 'Jane Doe', 'handle' => 'jane', 'handle_lc' => 'jane']);

    // Site::factory uses the SQLite 'pgsql' connection via BaseModel.
    $site = Site::factory()->create(['user_id' => $user->id]);

    // Production has trg_create_empty_design_kit; SQLite tests don't, so seed manually.
    DB::connection('pgsql')->table('site.design_kits')->insert(['site_id' => $site->id]);

    $enquiryId = seedInboxEnquiry($user->id, $site->id, array_merge([
        'email' => 'sam@example.com',
        'name' => 'Sam',
        'subject' => 'Hello',
        'confirmation_sent_at' => null,
    ], $enquiryOverrides));

    return [$user, $site, $enquiryId];
}

it('sends a brand-resolved enquiry confirmation', function () {
    Mail::fake();
    [, , $enquiryId] = makeConfirmableBrandEnquiry();

    (new SendEnquiryConfirmationJob($enquiryId))->handle();

    Mail::assertSent(EnquiryConfirmationMail::class, function (EnquiryConfirmationMail $m) {
        return $m->brand->proName === 'Jane Doe' && $m->visitorName === 'Sam';
    });

    $row = DB::connection('pgsql')->table('site.enquiries')->where('id', $enquiryId)->first();
    expect($row->confirmation_sent_at)->not->toBeNull();
});

it('leaves confirmation_sent_at unset when the send throws, so the retry re-sends (TXN-1 reversed)', function () {
    [, , $enquiryId] = makeConfirmableBrandEnquiry();

    // Simulate a mail-provider hiccup on the first attempt. #SEM-10 reversed:
    // the stamp is no longer claimed under the lock before the send — it is
    // written only after Mail::send() succeeds, so a throw here must leave it
    // unset and a later retry must actually re-send.
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));

    expect(fn () => (new SendEnquiryConfirmationJob($enquiryId))->handle())
        ->toThrow(RuntimeException::class);

    $row = DB::connection('pgsql')->table('site.enquiries')->where('id', $enquiryId)->first();
    expect($row->confirmation_sent_at)->toBeNull();

    // A fresh job instance (models a real Horizon retry) must now actually send.
    // Mail::shouldReceive() above swapped the facade root for a single-expectation
    // Mockery partial mock; swap in a real MailManager before Mail::fake() so the
    // fake doesn't wrap that now-exhausted mock.
    Mail::swap(new MailManager(app()));
    Mail::fake();
    (new SendEnquiryConfirmationJob($enquiryId))->handle();

    Mail::assertSent(EnquiryConfirmationMail::class, 1);

    $row = DB::connection('pgsql')->table('site.enquiries')->where('id', $enquiryId)->first();
    expect($row->confirmation_sent_at)->not->toBeNull();
});

it('falls back to the Partna brand (and still sends + logs) when resolution throws', function () {
    Mail::fake();
    [, $site, $enquiryId] = makeConfirmableBrandEnquiry();

    Log::spy();

    $this->mock(ProEmailBrandResolver::class, function ($mock) {
        $mock->shouldReceive('forSite')->andThrow(new RuntimeException('boom'));
        $mock->shouldReceive('partna')->andReturn(EmailBrand::partna());
    });

    (new SendEnquiryConfirmationJob($enquiryId))->handle();

    Mail::assertSent(EnquiryConfirmationMail::class, fn (EnquiryConfirmationMail $m) => $m->brand->isPartna === true);

    Log::shouldHaveReceived('warning')->withArgs(
        fn ($msg, $ctx = []) => str_contains((string) $msg, 'email brand') && ($ctx['site_id'] ?? null) === $site->id
    );
});
