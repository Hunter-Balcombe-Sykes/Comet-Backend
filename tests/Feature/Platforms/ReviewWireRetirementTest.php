<?php

use App\Http\Resources\Platforms\PublicIntegrationConnectionResource;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\DsarPayloadFilter;

/*
|--------------------------------------------------------------------------
| Slice 6 Task 8 — the legacy review READ is retired from the public wire
|--------------------------------------------------------------------------
| `reviews`, `reviewSummary`, `rating` and `reviewCount` leave the
| google-business public allowlist. Reviews reach the sitepage through
| `profile.pools.reviews` (content.f_review, which redaction, pruning and DSAR
| all govern) and the aggregates through content.source_stats.
|
| The FETCH is unchanged — GoogleBusinessService still populates the payload and
| GoogleBusinessConnectionResource (dashboard) still reads it. This retires a
| read, not a fetch.
*/

it('drops the four review keys from the google-business public allowlist, leaving the lane otherwise intact', function () {
    $allowlist = (new ReflectionClass(PublicIntegrationConnectionResource::class))
        ->getConstant('ALLOWLIST')['google-business'];

    expect($allowlist)
        ->not->toContain('reviews')
        ->not->toContain('reviewSummary')
        ->not->toContain('rating')
        ->not->toContain('reviewCount')
        // Everything else on that lane is untouched — this retires the review
        // surface, not the connection.
        ->toContain('photos')
        ->toContain('hours')
        ->toContain('address')
        ->toContain('links')
        ->toContain('editorialSummary');
});

it('serves no review keys on the public integrations wire', function () {
    $pro = createTenant('gbretire');

    IntegrationConnection::create([
        'user_id' => $pro->id,
        'platform' => 'google-business',
        'resource_id' => 'google-business',
        'payload' => [
            'name' => 'Fade Lab',
            'address' => '12 Example St',
            'hours' => ['weekdays' => ['Monday: 9:00 AM - 5:00 PM']],
            'rating' => 4.83,
            'reviewCount' => 12719,
            'reviewSummary' => 'Punters rave about the razor fades.',
            'reviews' => [['author' => 'Ada Reviewer', 'rating' => 5, 'text' => 'Sharpest cut in town']],
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $response = $this->getJson('/api/public/profiles/gbretire/integrations')->assertOk();
    $payload = $response->json('data.platforms.google-business.0.payload');

    // The connection still publishes — this is a retirement, not a blanking.
    expect($payload['name'])->toBe('Fade Lab')
        ->and($payload['address'])->toBe('12 Example St')
        ->and($payload['hours']['weekdays'])->toHaveCount(1)
        ->and($payload)->not->toHaveKey('rating')
        ->and($payload)->not->toHaveKey('reviewCount')
        ->and($payload)->not->toHaveKey('reviewSummary')
        ->and($payload)->not->toHaveKey('reviews');

    // Body-wide, not one JSON path: a key re-appearing under a differently
    // shaped payload would still fail. Values, because `rating` is an ordinary
    // key name on nested objects elsewhere on this wire.
    $body = $response->getContent();
    expect($body)->not->toContain('Ada Reviewer')
        ->and($body)->not->toContain('Sharpest cut in town')
        ->and($body)->not->toContain('Punters rave about the razor fades.')
        ->and($body)->not->toContain('4.83')
        ->and($body)->not->toContain('12719');

    // The fixture is real — the stored payload still carries all four keys, so
    // the assertions above prove a retirement rather than an empty database.
    expect(IntegrationConnection::query()->where('user_id', $pro->id)->value('payload'))
        ->toHaveKey('reviews');
});

// The 2026-08-05 precedent: DSAR allowlists deliberately RETAIN legacy keys so
// payloads stored before the retirement stay disclosable.
it('keeps the legacy keys in the DSAR third-party list', function () {
    expect(DsarPayloadFilter::THIRD_PARTY_KEYS)
        ->toContain('reviews')
        ->toContain('reviewSummary');
});

// Article 15 transparency: withholding is lawful because it is disclosed. The
// aggregate summary now has a content.* home (content.source_stats.summary_text)
// and is withheld there too, so the disclosure has to name it — while keeping
// the 2026-08-05 wording, backticked keys included.
it('names the aggregate review summary in the withheld disclosure', function () {
    expect(DsarPayloadFilter::WITHHELD_DISCLOSURE)
        ->toContain('`reviews`')
        ->toContain('`reviewSummary`')
        ->toContain('`organiser`')
        ->toContain('`venue`')
        ->toContain('summary')
        ->toContain('content.source_stats');
});
