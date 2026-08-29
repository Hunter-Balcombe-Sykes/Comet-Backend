<?php

use App\Models\Core\User\User;
use App\Providers\AppServiceProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    Queue::fake();

    config()->set('services.mistral.key', 'test-mistral-key');
    config()->set('services.deepseek.key', 'test-deepseek-key');
    config()->set('partna.limits.ai_spend.global_daily_cap', 1000);
    config()->set('partna.limits.ai_spend.actors.mistral_ocr', 500);
    config()->set('partna.limits.ai_spend.actors.deepseek_structure', 500);
});

function scanEndpointUser(string $h, string $sector = 'restaurant'): User
{
    $user = User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'business',
        'sector' => $sector,
        'primary_email' => "{$h}@example.com",
    ]);

    // auth_user_id is not mass-assignable — without it actingAsUser() mints a
    // fresh uid per call, splitting the per-user rate-limit bucket per request.
    $user->forceFill(['auth_user_id' => (string) Str::uuid()])->save();

    return $user;
}

function fakeMenuJpg(): UploadedFile
{
    return UploadedFile::fake()->image('menu.jpg', 800, 600);
}

function fakeMenuPdf(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('menu.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF");
}

function fakeOcrAndStructure(string $ocrMarkdown, ?array $items = null): void
{
    $items ??= [['name' => 'Negroni', 'description' => 'Gin, Campari, vermouth', 'price' => 14, 'category' => 'Cocktails', 'dietary' => null]];
    Http::fake([
        'api.mistral.ai/v1/ocr' => Http::response(['pages' => [['markdown' => $ocrMarkdown]]]),
        'api.deepseek.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode(['items' => $items])]]],
        ]),
    ]);
}

it('scans an uploaded image into items', function () {
    $user = scanEndpointUser('scanep1');
    fakeOcrAndStructure("COCKTAILS\nNegroni Gin Campari vermouth \$14");

    $res = actingAsUser($user)->post('/api/platforms/menu/scan', ['file' => fakeMenuJpg()])
        ->assertOk();

    expect($res->json('items'))->toBe([
        ['name' => 'Negroni', 'description' => 'Gin, Campari, vermouth', 'price' => 14, 'category' => 'Cocktails', 'dietary' => null],
    ]);

    // The OCR call carried a base64 data URI in image_url — never a hosted URL.
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'api.mistral.ai')) {
            return true;
        }
        $doc = $request->data()['document'] ?? [];

        return ($doc['type'] ?? null) === 'image_url'
            && str_starts_with((string) ($doc['image_url'] ?? ''), 'data:image/jpeg;base64,');
    });
});

it('scans an uploaded PDF via the document_url block', function () {
    $user = scanEndpointUser('scanep2');
    fakeOcrAndStructure("MAINS\nParma \$24");

    actingAsUser($user)->post('/api/platforms/menu/scan', ['file' => fakeMenuPdf()])->assertOk();

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'api.mistral.ai')) {
            return true;
        }
        $doc = $request->data()['document'] ?? [];

        return ($doc['type'] ?? null) === 'document_url'
            && str_starts_with((string) ($doc['document_url'] ?? ''), 'data:application/pdf;base64,');
    });
});

it('403s a non-food account before any billed call', function () {
    $user = scanEndpointUser('scanep3', sector: 'hairdressing');
    Http::fake();

    actingAsUser($user)->post('/api/platforms/menu/scan', ['file' => fakeMenuJpg()])
        ->assertStatus(403);

    Http::assertNothingSent();
});

it('503s when the AI keys are not configured', function () {
    config()->set('services.mistral.key', '');
    $user = scanEndpointUser('scanep4');
    Http::fake();

    actingAsUser($user)->post('/api/platforms/menu/scan', ['file' => fakeMenuJpg()])
        ->assertStatus(503)
        ->assertJsonPath('message', "Menu scanning isn't configured yet.");

    Http::assertNothingSent();
});

it('422s a missing or non-menu file type', function () {
    $user = scanEndpointUser('scanep5');
    Http::fake();

    actingAsUser($user)->post('/api/platforms/menu/scan', [], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('errors.file.0', 'Attach a menu photo or PDF.');

    $txt = UploadedFile::fake()->createWithContent('menu.txt', 'just text');
    actingAsUser($user)->post('/api/platforms/menu/scan', ['file' => $txt], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('errors.file.0', "That file type isn't supported. Use a JPG, PNG, WebP or PDF.");

    // Spoofed extension: text bytes named .jpg. A REAL UploadedFile (not the
    // Testing fake, whose getMimeType() guesses from the extension) so the
    // mimetypes rule runs finfo on actual bytes, as it does in production.
    $path = tempnam(sys_get_temp_dir(), 'menuscan');
    file_put_contents($path, 'not really an image');
    $spoofed = new UploadedFile($path, 'menu.jpg', 'image/jpeg', null, true);
    actingAsUser($user)->post('/api/platforms/menu/scan', ['file' => $spoofed], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('errors.file.0', "That file type isn't supported. Use a JPG, PNG, WebP or PDF.");

    Http::assertNothingSent();
});

it('422s an oversized file', function () {
    config()->set('partna.menu_scan_max_upload_size', 1); // 1 KB
    $user = scanEndpointUser('scanep6');
    Http::fake();

    actingAsUser($user)->post('/api/platforms/menu/scan', ['file' => fakeMenuJpg()], ['Accept' => 'application/json'])
        ->assertStatus(422);

    Http::assertNothingSent();
});

it('422s a pixel-bomb image before any billed OCR call', function () {
    $user = scanEndpointUser('scanep13');
    Http::fake();

    // A ~33-byte PNG header declaring 20000x20000 — real bytes, no rasterised
    // allocation. finfo reports image/png, getimagesizefromstring reports the
    // declared dimensions without decoding pixel data (#W1-SEC-2).
    $ihdr = pack('N', 20000).pack('N', 20000)."\x08\x02\x00\x00\x00";
    $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));
    $path = tempnam(sys_get_temp_dir(), 'menubomb');
    file_put_contents($path, $png);
    $bomb = new UploadedFile($path, 'menu.png', 'image/png', null, true);

    actingAsUser($user)->post('/api/platforms/menu/scan', ['file' => $bomb], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('errors.file.0', 'That image is too big to process. Use one under 24 megapixels.');

    Http::assertNothingSent();
});

it('422s when OCR reads nothing menu-like', function () {
    $user = scanEndpointUser('scanep7');
    Http::fake([
        'api.mistral.ai/v1/ocr' => Http::response(['pages' => [['markdown' => '   ']]]),
    ]);

    actingAsUser($user)->post('/api/platforms/menu/scan', ['file' => fakeMenuJpg()])
        ->assertStatus(422)
        ->assertJsonPath('message', "We couldn't read any menu items from that file. Try a clearer photo or a different page.");
});

it('502s when OCR hard-fails', function () {
    $user = scanEndpointUser('scanep8');
    Http::fake(['api.mistral.ai/v1/ocr' => Http::response(null, 500)]);

    actingAsUser($user)->post('/api/platforms/menu/scan', ['file' => fakeMenuJpg()])
        ->assertStatus(502)
        ->assertJsonPath('message', 'The menu scanner is having trouble right now. Try again in a moment.');
});

it('502s when structuring hard-fails', function () {
    $user = scanEndpointUser('scanep9');
    Http::fake([
        'api.mistral.ai/v1/ocr' => Http::response(['pages' => [['markdown' => 'MAINS Parma $24']]]),
        'api.deepseek.com/*' => Http::response(null, 500),
    ]);

    actingAsUser($user)->post('/api/platforms/menu/scan', ['file' => fakeMenuJpg()])
        ->assertStatus(502);
});

it('422s when structuring finds no items', function () {
    $user = scanEndpointUser('scanep10');
    fakeOcrAndStructure('MAINS Parma $24', items: []);

    actingAsUser($user)->post('/api/platforms/menu/scan', ['file' => fakeMenuJpg()])
        ->assertStatus(422);
});

it('429s after the per-user daily scan cap', function () {
    // Limiter closures captured $throttleEnabled at original boot — re-run the
    // provider's registration with the override live (the codebase's standing
    // pattern, see PublicRateLimiterCfConnectingIpTest).
    config()->set('partna.throttle.enabled', true);
    config()->set('partna.throttle.menu_scan_per_day', 1);
    $configureRateLimiting = new ReflectionMethod(AppServiceProvider::class, 'configureRateLimiting');
    $configureRateLimiting->invoke(new AppServiceProvider(app()));
    $user = scanEndpointUser('scanep12');
    fakeOcrAndStructure("MAINS\nParma \$24");

    actingAsUser($user)->post('/api/platforms/menu/scan', ['file' => fakeMenuJpg()])->assertOk();
    actingAsUser($user)->post('/api/platforms/menu/scan', ['file' => fakeMenuJpg()])
        ->assertStatus(429)
        ->assertJsonPath('message', "You've reached today's scan limit. Try again tomorrow.");
});

it('502s when the daily AI spend budget is exhausted', function () {
    config()->set('partna.limits.ai_spend.actors.mistral_ocr', 0);
    $user = scanEndpointUser('scanep11');
    Http::fake();

    actingAsUser($user)->post('/api/platforms/menu/scan', ['file' => fakeMenuJpg()])
        ->assertStatus(502);

    Http::assertNothingSent();
});
