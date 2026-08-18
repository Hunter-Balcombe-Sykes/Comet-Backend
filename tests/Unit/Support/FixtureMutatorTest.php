<?php

// tests/Unit/Support/FixtureMutatorTest.php

use Tests\Support\Fixtures\Recorded;

$base = ['fullName' => 'Jane Doe', 'biography' => 'Hair by Jane', 'externalUrl' => 'https://x', 'postsCount' => 56, 'latestPosts' => [['id' => 1]], 'businessCategoryName' => 'Hair salon'];

it('drops keys with without()', function () use ($base) {
    expect(Recorded::mutate($base)->without('biography', 'externalUrl')->get())
        ->not->toHaveKeys(['biography', 'externalUrl'])
        ->toHaveKey('fullName');
});

it('nullifies and sets by dot key', function () use ($base) {
    $out = Recorded::mutate($base)->nullify('externalUrl')->set('postsCount', 0)->set('latestPosts.0.id', 99)->get();
    expect($out['externalUrl'])->toBeNull()
        ->and($out['postsCount'])->toBe(0)
        ->and($out['latestPosts'][0]['id'])->toBe(99);
});

it('empties arrays', function () use ($base) {
    expect(Recorded::mutate($base)->emptyArray('latestPosts')->get()['latestPosts'])->toBe([]);
});

it('snake_cases every key recursively — the SIGNUP-2 shape', function () use ($base) {
    $out = Recorded::mutate($base)->snakeCaseKeys()->get();
    expect($out)->toHaveKeys(['full_name', 'external_url', 'posts_count', 'business_category_name'])
        ->not->toHaveKey('fullName');
});

it('camelCases keys back and round-trips', function () use ($base) {
    expect(Recorded::mutate($base)->snakeCaseKeys()->camelCaseKeys()->get())->toBe($base);
});

it('does not mutate the original payload', function () use ($base) {
    $m = Recorded::mutate($base);
    $m->without('fullName');
    expect($m->get())->toBe($base);
});

it('distinguishes nullify (key present, value null) from without (key absent)', function () use ($base) {
    $nullified = Recorded::mutate($base)->nullify('externalUrl')->get();
    $dropped = Recorded::mutate($base)->without('externalUrl')->get();

    expect(array_key_exists('externalUrl', $nullified))->toBeTrue()
        ->and($nullified['externalUrl'])->toBeNull()
        ->and(array_key_exists('externalUrl', $dropped))->toBeFalse();
});

it('snake-cases keys recursively, not just at the top level', function () {
    $nested = ['topLevelKey' => 'v', 'latestPosts' => [['postId' => 1, 'ownerHandle' => 'jane']]];

    $out = Recorded::mutate($nested)->snakeCaseKeys()->get();

    expect($out)->toHaveKeys(['top_level_key', 'latest_posts'])
        ->and($out['latest_posts'][0])->toHaveKeys(['post_id', 'owner_handle'])
        ->and($out['latest_posts'][0])->not->toHaveKey('postId');
});

it('does not mutate the caller-held reference to the base payload across independent builder chains', function () use ($base) {
    $a = Recorded::mutate($base)->without('fullName')->get();
    $b = Recorded::mutate($base)->set('postsCount', 0)->get();

    expect($a)->not->toHaveKey('fullName')
        ->and($a['postsCount'])->toBe(56)
        ->and($b['postsCount'])->toBe(0)
        ->and($b)->toHaveKey('fullName');
});
