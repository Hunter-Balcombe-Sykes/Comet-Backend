<?php

use App\Services\Profile\BioIntelligence;
use Illuminate\Support\Facades\Http;

/**
 * T5/T13/T14/T16 (2026-08-27): the mechanical gates around the bio model.
 * The MODEL's judgement is checked live at acceptance; these tests pin the
 * property that matters structurally — nothing the model says is believed
 * unless it is made of the user's own words (names), the biography's own
 * words (about), or literal bio content (email/phone/mentions).
 */
beforeEach(function () {
    config(['services.deepseek.key' => 'test-key']);
    config(['partna.limits.ai_spend.actors.deepseek_bio' => 100, 'partna.limits.ai_spend.global_daily_cap' => 1000]);
});

function bioAiRespond(array $payload): void
{
    Http::fake([
        'api.deepseek.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode($payload)]]],
        ]),
    ]);
}

const BIL_BIO = "Co- Owner @studio___san Blackburn South VIC 3130\n@andisco_aunz ambassador.\nBookings 👇 barber.thorton@example.com";

it('passes a fully-grounded result through every gate (barber_in_law shape)', function () {
    bioAiRespond([
        'display_name' => 'Thorton',
        'first_name' => 'Thorton',
        'last_name' => null,
        'about' => 'Co-owner of Studio San in Blackburn South. Andis ambassador.',
        'email' => 'barber.thorton@example.com',
        'phone' => null,
        'mentions' => [
            ['handle' => 'studio___san', 'label' => 'Co- Owner @studio___san Blackburn South VIC 3130', 'type' => 'workplace'],
            ['handle' => 'andisco_aunz', 'label' => '@andisco_aunz ambassador.', 'type' => 'brand'],
        ],
    ]);

    $result = app(BioIntelligence::class)->analyse('barber_in_law', 'Melbourne Barber | Thorton', BIL_BIO);

    expect($result['aiUsed'])->toBeTrue();
    expect($result['displayName'])->toBe('Thorton');
    expect($result['firstName'])->toBe('Thorton');
    expect($result['email'])->toBe('barber.thorton@example.com');
    expect(collect($result['mentions'])->pluck('type', 'handle')->all())
        ->toBe(['studio___san' => 'workplace', 'andisco_aunz' => 'brand']);
    // "ambassador"/"blackburn" are bio words — the about survives overlap.
    expect($result['about'])->not->toBeNull();
});

it('rejects an invented display name and a descriptor surname', function () {
    bioAiRespond([
        'display_name' => 'Samuel Atherton', // neither word is theirs
        'first_name' => 'Sam',
        'last_name' => 'Music', // descriptor — never a surname
        'about' => null, 'email' => null, 'phone' => null, 'mentions' => [],
    ]);

    $result = app(BioIntelligence::class)->analyse('sammy.pdf', 'Sam Akhurst Music', 'Producer.');

    expect($result['displayName'])->toBeNull();
    expect($result['firstName'])->toBe('Sam');
    expect($result['lastName'])->toBeNull();
});

it('accepts a name assembled from their own words', function () {
    bioAiRespond([
        'display_name' => 'Sam Akhurst',
        'first_name' => 'Sam',
        'last_name' => 'Akhurst',
        'about' => null, 'email' => null, 'phone' => null, 'mentions' => [],
    ]);

    $result = app(BioIntelligence::class)->analyse('sammy.pdf', 'Sam Akhurst Music', null);

    expect($result['displayName'])->toBe('Sam Akhurst');
    expect($result['lastName'])->toBe('Akhurst');
});

it('nulls an about that fails the their-words overlap, carries links, or carries emoji', function () {
    $svc = app(BioIntelligence::class);
    $bio = 'Barber in Brisbane. Fades and beard work.';

    // Http::fake stubs are first-match-wins, so one sequence serves the four calls.
    $wrap = fn (string $about) => json_encode(['choices' => [['message' => ['content' => json_encode([
        'about' => $about, 'display_name' => null, 'first_name' => null, 'last_name' => null,
        'email' => null, 'phone' => null, 'mentions' => [],
    ])]]]]);
    Http::fake([
        'api.deepseek.com/*' => Http::sequence()
            ->push(json_decode($wrap('An award-winning celebrity stylist serving international clients.'), true))
            ->push(json_decode($wrap('Barber in Brisbane — book at https://example.com'), true))
            ->push(json_decode($wrap('Barber in Brisbane ✂️ fades and beard work.'), true))
            ->push(json_decode($wrap('Barber in Brisbane specialising in fades and beard work.'), true)),
    ]);

    expect($svc->analyse('x', null, $bio)['about'])->toBeNull();       // invention
    expect($svc->analyse('x', null, $bio)['about'])->toBeNull();       // link
    expect($svc->analyse('x', null, $bio)['about'])->toBeNull();       // emoji
    expect($svc->analyse('x', null, $bio)['about'])->toBe('Barber in Brisbane specialising in fades and beard work.');
});

it('accepts contact details only when literally present in the bio', function () {
    $bio = 'Bookings: 0400 123 456';

    bioAiRespond(['email' => 'made.up@example.com', 'phone' => '0400 123 456', 'display_name' => null, 'first_name' => null, 'last_name' => null, 'about' => null, 'mentions' => []]);
    $result = app(BioIntelligence::class)->analyse('x', null, $bio);

    expect($result['email'])->toBeNull();
    expect($result['phone'])->toBe('0400 123 456');
});

it('drops mentions that are not actually in the bio and junk handles', function () {
    bioAiRespond(['mentions' => [
        ['handle' => 'studio___san', 'label' => 'Owner', 'type' => 'workplace'],
        ['handle' => 'conjured_salon', 'label' => 'Owner', 'type' => 'workplace'],
        ['handle' => 'BAD HANDLE!!', 'label' => 'x', 'type' => 'brand'],
    ], 'display_name' => null, 'first_name' => null, 'last_name' => null, 'about' => null, 'email' => null, 'phone' => null]);

    $result = app(BioIntelligence::class)->analyse('x', null, 'Owner @studio___san');

    expect(collect($result['mentions'])->pluck('handle')->all())->toBe(['studio___san']);
});

it('returns the empty shape without any HTTP call when unconfigured', function () {
    config(['services.deepseek.key' => '']);
    Http::fake();

    $result = app(BioIntelligence::class)->analyse('x', 'Full Name', 'A bio.');

    expect($result['aiUsed'])->toBeFalse();
    expect($result['displayName'])->toBeNull();
    Http::assertNothingSent();
});
