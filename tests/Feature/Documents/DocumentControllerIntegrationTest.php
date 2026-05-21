<?php

use App\Http\Controllers\Api\Professional\Account\ProfessionalDocumentController;
use App\Http\Requests\Api\Professional\Documents\UpdateDocumentRequest;
use App\Http\Requests\Api\Professional\Documents\UploadDocumentRequest;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\SiteCacheService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Documents\DocumentTestCase;

/**
 * Behavioural integration tests for ProfessionalDocumentController. These
 * bypass HTTP auth middleware by invoking the controller directly and
 * injecting the authenticated pro via the `professional` request attribute
 * (the same pattern ReadOnlyEnforcementTest uses). Storage::fake('media')
 * isolates R2 interactions so we can assert file operations.
 */
beforeEach(function () {
    DocumentTestCase::boot();
    Storage::fake('media');

    // Mock SiteCacheService — its real invalidateSite() queries tables
    // (site_subdomain_aliases, brand_partner_links) we don't stub in
    // DocumentTestCase. Tests that care about cache behaviour override
    // this instance explicitly.
    $stub = Mockery::mock(SiteCacheService::class);
    $stub->shouldReceive('invalidateSite')->zeroOrMoreTimes();
    app()->instance(SiteCacheService::class, $stub);
});

function seedProfessional(string $type = 'professional'): Professional
{
    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $proId,
        'handle' => 'p-'.substr($proId, 0, 8),
        'handle_lc' => 'p-'.substr($proId, 0, 8),
        'display_name' => 'Test Pro',
        'primary_email' => 'p-'.substr($proId, 0, 8).'@example.com',
        'status' => 'active',
        'professional_type' => $type,
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'professional_id' => $proId,
        'subdomain' => 'p-'.substr($proId, 0, 8),
        'is_published' => 1,
    ]);

    return Professional::query()->where('id', $proId)->first();
}

function uploadRequestFor(Professional $pro, UploadedFile $file, string $title, ?string $caption = null): UploadDocumentRequest
{
    $payload = ['title' => $title];
    if ($caption !== null) {
        $payload['caption'] = $caption;
    }
    $base = Request::create('/api/documents', 'POST', $payload, [], ['file' => $file]);
    $base->attributes->set('professional', $pro);

    $req = UploadDocumentRequest::createFrom($base);
    $req->setContainer(app())->setRedirector(app('redirect'));
    $req->validateResolved();

    // Re-attach the authenticated pro to the post-validation form request.
    $req->attributes->set('professional', $pro);

    return $req;
}

it('POST /api/documents creates a SiteMedia row and stores the file on R2', function () {
    $pro = seedProfessional();
    $file = UploadedFile::fake()->create('schedule.pdf', 200, 'application/pdf');

    // Put actual PDF bytes into the fake upload so finfo returns application/pdf.
    file_put_contents($file->getRealPath(), "%PDF-1.4\n%%EOF\n".str_repeat('x', 200 * 1024 - 12));

    $controller = app(ProfessionalDocumentController::class);
    $response = $controller->store(uploadRequestFor($pro, $file, 'Education Schedule'));

    expect($response->status())->toBe(201);

    $row = SiteMedia::query()
        ->where('site_id', $pro->site->id)
        ->where('pool', 'documents')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->alt_text)->toBe('Education Schedule');
    expect($row->original_mime)->toBe('application/pdf');
    expect($row->original_filename)->toBe('schedule.pdf');
    expect($row->path)->toStartWith("documents/{$pro->id}/{$row->id}/original.pdf");

    // File was put to the faked media disk.
    Storage::disk('media')->assertExists($row->path);
});

it('POST /api/documents strips CRLF and path-traversal from original_filename', function () {
    $pro = seedProfessional();
    // Filename combines path traversal (../../etc/) and CRLF injection (\r\n) —
    // both should be neutralised before storage.
    $file = UploadedFile::fake()->create("../../etc/\r\npasswd.pdf", 50, 'application/pdf');
    file_put_contents($file->getRealPath(), "%PDF-1.4\n%%EOF\n".str_repeat('x', 50 * 1024 - 12));

    $response = app(ProfessionalDocumentController::class)
        ->store(uploadRequestFor($pro, $file, 'Test'));

    expect($response->status())->toBe(201);

    $row = SiteMedia::query()->where('site_id', $pro->site->id)->first();
    // basename() removes the path prefix; CRLF control chars are then stripped.
    expect($row->original_filename)->toBe('passwd.pdf');
    expect($row->original_filename)->not->toContain("\r");
    expect($row->original_filename)->not->toContain("\n");
});

it('POST /api/documents flat-replaces: old row soft-deleted, old R2 bytes removed, new active', function () {
    $pro = seedProfessional();

    // First upload
    $file1 = UploadedFile::fake()->create('v1.pdf', 100, 'application/pdf');
    file_put_contents($file1->getRealPath(), "%PDF-1.4\n%%EOF\n".str_repeat('a', 100 * 1024 - 12));
    app(ProfessionalDocumentController::class)
        ->store(uploadRequestFor($pro, $file1, 'Version 1'));

    $firstRow = SiteMedia::query()->where('site_id', $pro->site->id)->first();
    $firstPath = $firstRow->path;
    Storage::disk('media')->assertExists($firstPath);

    // Second upload (replacement)
    $file2 = UploadedFile::fake()->create('v2.pdf', 150, 'application/pdf');
    file_put_contents($file2->getRealPath(), "%PDF-1.4\n%%EOF\n".str_repeat('b', 150 * 1024 - 12));
    app(ProfessionalDocumentController::class)
        ->store(uploadRequestFor($pro, $file2, 'Version 2'));

    // First row should be soft-deleted, first R2 bytes gone.
    $firstRowAfter = SiteMedia::withTrashed()->find($firstRow->id);
    expect($firstRowAfter->deleted_at)->not->toBeNull();
    Storage::disk('media')->assertMissing($firstPath);

    // Second row should be the only active one.
    $activeRows = SiteMedia::query()
        ->where('site_id', $pro->site->id)
        ->where('pool', 'documents')
        ->where('is_active', true)
        ->whereNull('deleted_at')
        ->get();
    expect($activeRows)->toHaveCount(1);
    expect($activeRows->first()->alt_text)->toBe('Version 2');
    Storage::disk('media')->assertExists($activeRows->first()->path);
});


it('POST /api/documents returns 415 when finfo detects MIME mismatch', function () {
    $pro = seedProfessional();
    // Create a file with .pdf extension but PNG-ish bytes → mimes rule passes
    // (trusts ClientOriginalName), finfo rejects.
    $file = UploadedFile::fake()->create('fake.pdf', 10, 'application/pdf');
    file_put_contents($file->getRealPath(), "\x89PNG\r\n\x1a\n".str_repeat("\x00", 1024));

    $controller = app(ProfessionalDocumentController::class);
    $response = $controller->store(uploadRequestFor($pro, $file, 'Trick me'));

    expect($response->status())->toBe(415);
});

it('PATCH /api/documents/{id} returns 404 for a document belonging to another site', function () {
    $proA = seedProfessional();
    $proB = seedProfessional();

    // Seed a document for pro A directly.
    $docId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $docId,
        'site_id' => $proA->site->id,
        'pool' => 'documents',
        'media_type' => 'document',
        'path' => "documents/{$proA->id}/{$docId}/original.pdf",
        'alt_text' => 'A Doc',
        'original_mime' => 'application/pdf',
        'original_filename' => 'a.pdf',
        'processing_state' => 'ready',
        'is_active' => 1,
        'sort_order' => 0,
    ]);

    // Pro B tries to PATCH pro A's document.
    $doc = SiteMedia::find($docId);
    $base = Request::create("/api/documents/{$docId}", 'PATCH', ['title' => 'Hacked']);
    $base->attributes->set('professional', $proB);
    $req = UpdateDocumentRequest::createFrom($base);
    $req->setContainer(app())->setRedirector(app('redirect'));
    $req->validateResolved();
    $req->attributes->set('professional', $proB);

    $controller = app(ProfessionalDocumentController::class);

    // SitePolicy throws AuthorizationException(404) — not HttpException — since
    // ownership now resolves through denyAsNotFound() rather than abort_unless().
    try {
        $controller->update($req, $doc);
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(404);
    }
});

it('PATCH /api/documents/{id} allows editing an inactive (is_active=false) document', function () {
    $pro = seedProfessional();
    $docId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $docId,
        'site_id' => $pro->site->id,
        'pool' => 'documents',
        'media_type' => 'document',
        'path' => "documents/{$pro->id}/{$docId}/original.pdf",
        'alt_text' => 'Inactive Doc',
        'original_mime' => 'application/pdf',
        'processing_state' => 'ready',
        'is_active' => 0,
        'sort_order' => 0,
    ]);

    $doc = SiteMedia::find($docId);
    $base = Request::create("/api/documents/{$docId}", 'PATCH', ['title' => 'Try to edit']);
    $base->attributes->set('professional', $pro);
    $req = UpdateDocumentRequest::createFrom($base);
    $req->setContainer(app())->setRedirector(app('redirect'));
    $req->validateResolved();
    $req->attributes->set('professional', $pro);

    // Controller intentionally allows editing draft (is_active=false) docs so
    // the publish toggle can flip them back to live — ownership is the only gate.
    $response = app(ProfessionalDocumentController::class)->update($req, $doc);
    expect($response->status())->toBe(200);
});

it('DELETE /api/documents/{id} allows deleting an inactive document', function () {
    $pro = seedProfessional();
    $docId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $docId,
        'site_id' => $pro->site->id,
        'pool' => 'documents',
        'media_type' => 'document',
        'path' => "documents/{$pro->id}/{$docId}/original.pdf",
        'alt_text' => 'Inactive Doc',
        'original_mime' => 'application/pdf',
        'processing_state' => 'ready',
        'is_active' => 0,
        'sort_order' => 0,
    ]);

    $doc = SiteMedia::find($docId);
    $base = Request::create("/api/documents/{$docId}", 'DELETE');
    $base->attributes->set('professional', $pro);

    // Controller intentionally allows deleting draft (is_active=false) docs —
    // the comment says "allows deleting draft docs too". Ownership is the only gate.
    $response = app(ProfessionalDocumentController::class)->destroy($base, $doc);
    expect($response->status())->toBe(200);
});

it('PATCH /api/documents/{id} skips cache invalidation when no field actually changed', function () {
    $pro = seedProfessional();

    // Upload an initial document so there's a row to PATCH.
    $file = UploadedFile::fake()->create('s.pdf', 50, 'application/pdf');
    file_put_contents($file->getRealPath(), "%PDF-1.4\n%%EOF\n".str_repeat('a', 50 * 1024 - 12));
    app(ProfessionalDocumentController::class)->store(uploadRequestFor($pro, $file, 'Initial title'));

    $doc = SiteMedia::query()->where('site_id', $pro->site->id)->first();

    // Spy on SiteCacheService — assert invalidateSite is never called on no-op PATCH.
    $spy = Mockery::mock(SiteCacheService::class);
    $spy->shouldNotReceive('invalidateSite');
    app()->instance(SiteCacheService::class, $spy);

    // PATCH with the SAME title — should be a no-op via isDirty guard.
    $base = Request::create("/api/documents/{$doc->id}", 'PATCH', ['title' => 'Initial title']);
    $base->attributes->set('professional', $pro);
    $req = UpdateDocumentRequest::createFrom($base);
    $req->setContainer(app())->setRedirector(app('redirect'));
    $req->validateResolved();
    $req->attributes->set('professional', $pro);

    $response = app(ProfessionalDocumentController::class)->update($req, $doc);

    expect($response->status())->toBe(200);
    // Mockery verifies shouldNotReceive at teardown — if invalidateSite had
    // been called, this test would fail automatically.
});
