<?php

use App\Http\Resources\Platforms\InstagramConnectionResource;
use App\Services\Platforms\Payloads\InstagramPayload;
use Tests\TestCase;

// resolve() injects a Request via the container, so the resource-equivalence
// cases need the app booted (mirrors tests/Unit/Platforms/Payloads/FeedPayloadTest.php).
uses(TestCase::class)->in(__FILE__);

it('hydrates the stored instagram payload and round-trips the public + internal keys', function () {
    $raw = [
        'username' => 'acme', 'fullName' => 'Acme Co', 'profilePicUrl' => 'https://r2/p.jpg',
        'businessCategory' => 'Cafe', 'followersCount' => 1200, 'postsCount' => 88,
        'mode' => 'automatic', 'images' => ['https://r2/0.jpg'],
        'videoUrl' => 'https://r2/reel.mp4', 'videoPoster' => 'https://r2/cover.jpg',
        '_folder' => 'platforms/instagram/1700000000', 'source' => 'google-business',
    ];

    $dto = InstagramPayload::fromArray($raw);

    expect($dto->username)->toBe('acme');
    expect($dto->followersCount)->toBe(1200);
    expect($dto->images)->toBe(['https://r2/0.jpg']);
    expect($dto->folder)->toBe('platforms/instagram/1700000000');
    expect($dto->source)->toBe('google-business');
    // toArray carries the internal keys back (the honest schema).
    expect($dto->toArray()['_folder'])->toBe('platforms/instagram/1700000000');
    expect($dto->toArray()['source'])->toBe('google-business');
});

it('is lenient — missing keys become canonical defaults', function () {
    $dto = InstagramPayload::fromArray(['username' => 'acme']);

    expect($dto->images)->toBe([]);
    expect($dto->videoUrl)->toBeNull();
    expect($dto->imagesDropped)->toBe(0);
    expect($dto->folder)->toBeNull();
    expect($dto->source)->toBeNull();
});

it('tolerates a non-array payload (pending placeholder / garbage)', function () {
    expect(InstagramPayload::fromArray(null)->username)->toBeNull();
    expect(InstagramPayload::fromArray('nope')->folder)->toBeNull();
});

it('resource output is identical whether fed the raw payload or the DTO round-trip, and never leaks _folder/source', function () {
    $raw = [
        'username' => 'acme', 'fullName' => 'Acme Co', 'profilePicUrl' => 'https://r2/p.jpg',
        'businessCategory' => 'Cafe', 'followersCount' => 1200, 'postsCount' => 88,
        'mode' => 'automatic', 'images' => ['https://r2/0.jpg'],
        'videoUrl' => 'https://r2/reel.mp4', 'videoPoster' => 'https://r2/cover.jpg',
        '_folder' => 'platforms/instagram/1700000000', 'source' => 'google-business',
    ];

    $fromRaw = (new InstagramConnectionResource($raw))->resolve();
    $fromDto = (new InstagramConnectionResource(InstagramPayload::fromArray($raw)->toArray()))->resolve();

    expect($fromDto)->toBe($fromRaw);
    expect($fromDto)->not->toHaveKey('_folder');
    expect($fromDto)->not->toHaveKey('source');
});
