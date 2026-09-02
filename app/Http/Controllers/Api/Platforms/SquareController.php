<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\PlatformConnectRequest;
use App\Http\Requests\Platforms\SaveSquareSelectionRequest;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Platforms\Payloads\SelectionPayload;
use App\Services\Platforms\Registry\Platform;
use App\Services\Platforms\SquareBookingClient;
use App\Services\Platforms\SquareBookingPage;
use App\Services\Platforms\SquareSiteBookingResolver;
use App\Services\Platforms\StaffNameMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

// Square Appointments — a "Book now" deep link, and since 2026-09-02 Fresha's
// shape around it: the booking page's roster (GET /team), a team-member
// selection (POST /selection) that is the URL's own team_member_id param,
// and the storewide widening for accounts that book the whole venue. The
// services themselves land through the square_book ingest connector, keyed
// off the URL — rewriting the URL is what re-dates the source. Fresha and
// Square are mutually exclusive booking providers (XOR) — only one may be
// connected at a time, enforced under the shared
// CacheKeyGenerator::bookingXorLock (U1, 2026-07-25) — see
// ManagesIntegrationConnection::withCrossPlatformLock.
class SquareController extends ApiController
{
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    private const NO_URL = 'No Square link saved yet. Save one first.';

    private const NOT_A_BOOKING_PAGE = 'That Square link is a website, not a booking page — paste the "Book now" link from Square Appointments (book.squareup.com/appointments/…).';

    public function __construct(
        private readonly SquareBookingClient $client,
        private readonly StaffNameMatcher $staffMatcher,
        private readonly SquareSiteBookingResolver $resolver,
    ) {}

    protected function platform(): string
    {
        return Platform::Square->value;
    }

    // POST /api/platforms/square/connect
    public function connect(PlatformConnectRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        // Sector-derived gate (2026-07-15): a food business books via
        // Reservations, not Booking — Square is a bespoke connect flow (never
        // routes through GenericPlatformController), so it needs its own check.
        if (! AccountCapabilities::for($user)->can_use_booking) {
            return $this->error('Booking is not available for your account.', 403);
        }

        // $url is read from the FormRequest before the lock — pure, already
        // validated, nothing slow. A *.square.site root is a website, not a
        // booking page: one GET (outside the lock) resolves it to the
        // Appointments deep link the site links out to, when it does.
        // U1: the conflict check moves INSIDE the lock (was a bare
        // unsynchronised exists() before this unit) — no per-platform
        // 'square' lock is taken, because every writer of the square row
        // (GoogleBusinessAutoSync::seedBooking, InstagramAutoSync::
        // resolveBookingLink, BuildsAutoSyncFindings::applyFinding, and this
        // connect itself) is already serialised on bookingXorLock alone.
        $url = $request->validated()['url'];
        $url = $this->resolver->resolve($url) ?? $url;

        return $this->withCrossPlatformLock(CacheKeyGenerator::bookingXorLock((string) $user->id), function () use ($user, $url): JsonResponse {
            if (($conflict = $this->bookingProviderConflict($user)) !== null) {
                return $conflict;
            }
            $this->writeConnection($user, ['url' => $url]);

            return $this->success(['url' => $url]);
        });
    }

    // GET /api/platforms/square/team — the booking page's staff, with the
    // one we think is you (the URL's own team member, else a name match).
    public function team(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $url = $this->squareUrl($user);
        if ($url === null) {
            return $this->error('No Square link connected yet. POST one to /connect first.', 404);
        }
        $parsed = SquareBookingPage::parseUrl($url);
        if ($parsed['merchant'] === null) {
            return $this->error(self::NOT_A_BOOKING_PAGE, 422);
        }

        $doc = $this->widgetOrAbort($parsed['merchant'], $parsed['unit']);
        $team = SquareBookingPage::team($doc);
        $onRoster = $parsed['teamMember'] !== null
            ? collect($team)->firstWhere('employeeId', $parsed['teamMember'])
            : null;

        // A link that names a team member we have not stored yet (harvested
        // from a bio, pasted with the param) — remember who that is so
        // /selection can say it without another fetch.
        $row = $this->connectionFor($user);
        $stored = $this->readConnection($user) ?? [];
        if ($onRoster !== null && $row !== null && ! is_array($stored['teamMember'] ?? null) && ! $user->isPendingDeletion()) {
            $row->payload = [...$stored, 'teamMember' => $this->memberSummary($onRoster)];
            $row->saveQuietly();
        }

        return $this->success([
            'url' => $url,
            'team' => $team,
            'suggestedEmployeeId' => $onRoster['employeeId'] ?? $this->staffMatcher->match($user, $team),
        ]);
    }

    // GET /api/platforms/square/selection — the saved URL and what it
    // selects: one team member (the URL's team_member_id) or the whole venue.
    public function selection(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $raw = $this->readConnection($user) ?? [];
        $url = SelectionPayload::fromArray($raw)->url;
        if ($url === null) {
            return $this->success(['url' => null, 'selection' => null]);
        }
        $parsed = SquareBookingPage::parseUrl($url);
        $stored = is_array($raw['teamMember'] ?? null) ? $raw['teamMember'] : null;
        $employee = null;
        if ($parsed['teamMember'] !== null) {
            $employee = $stored !== null && ($stored['employeeId'] ?? null) === $parsed['teamMember']
                ? $stored
                : ['employeeId' => $parsed['teamMember'], 'displayName' => null, 'jobTitle' => null, 'avatarUrl' => null];
        }

        return $this->success([
            'url' => $url,
            'selection' => [
                'mode' => $parsed['teamMember'] !== null ? 'employee' : 'storewide',
                'employee' => $employee,
            ],
            ...(($raw['autoSelected'] ?? false) ? [
                'autoSelected' => true,
                'matchTier' => $raw['matchTier'] ?? null,
            ] : []),
        ]);
    }

    // POST /api/platforms/square/selection {employeeId} — narrow the link to
    // one team member. Re-resolved against the live roster so a departed
    // member 404s rather than saving; the rewrite re-dates the ingest source.
    public function saveSelection(SaveSquareSelectionRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $employeeId = (string) $request->validated()['employeeId'];
        $url = $this->squareUrl($user);
        if ($url === null) {
            return $this->error(self::NO_URL, 404);
        }
        $parsed = SquareBookingPage::parseUrl($url);
        if ($parsed['merchant'] === null) {
            return $this->error(self::NOT_A_BOOKING_PAGE, 422);
        }

        $doc = $this->widgetOrAbort($parsed['merchant'], $parsed['unit']);
        $member = collect(SquareBookingPage::team($doc))->firstWhere('employeeId', $employeeId);
        if ($member === null) {
            return $this->error('That team member was not found on the saved Square page.', 404);
        }
        $newUrl = SquareBookingPage::bookingUrl($parsed['merchant'], $parsed['unit'] ?? SquareBookingPage::unitToken($doc), $employeeId);
        $summary = $this->memberSummary($member);

        return $this->withCrossPlatformLock(CacheKeyGenerator::bookingXorLock((string) $user->id), function () use ($user, $newUrl, $summary): JsonResponse {
            if (($conflict = $this->bookingProviderConflict($user)) !== null) {
                return $conflict;
            }

            return $this->withConnectionLock($user, function () use ($user, $newUrl, $summary): JsonResponse {
                $existing = $this->readConnection($user);
                if ($existing === null) {
                    return $this->error(self::NO_URL, 404);
                }
                unset($existing['autoSelected'], $existing['matchTier']);
                $this->writeConnection($user, [...$existing, 'url' => $newUrl, 'teamMember' => $summary]);

                return $this->success(['url' => $newUrl, 'selection' => ['mode' => 'employee', 'employee' => $summary]]);
            });
        });
    }

    // POST /api/platforms/square/selection/storewide — widen back to the
    // whole venue: the URL without its team_member_id.
    public function saveStorewide(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        if (! AccountCapabilities::for($user)->can_book_storewide) {
            return $this->error('This account books one team member at a time.', 403);
        }
        $url = $this->squareUrl($user);
        if ($url === null) {
            return $this->error(self::NO_URL, 404);
        }
        $parsed = SquareBookingPage::parseUrl($url);
        if ($parsed['merchant'] === null) {
            return $this->error(self::NOT_A_BOOKING_PAGE, 422);
        }
        $newUrl = SquareBookingPage::bookingUrl($parsed['merchant'], $parsed['unit'], null);

        return $this->withConnectionLock($user, function () use ($user, $newUrl): JsonResponse {
            $existing = $this->readConnection($user);
            if ($existing === null) {
                return $this->error(self::NO_URL, 404);
            }
            unset($existing['autoSelected'], $existing['matchTier'], $existing['teamMember']);
            $this->writeConnection($user, [...$existing, 'url' => $newUrl, 'teamMember' => null]);

            return $this->success(['url' => $newUrl, 'selection' => ['mode' => 'storewide', 'employee' => null]]);
        });
    }

    // DELETE /api/platforms/square — clear the saved URL.
    public function forget(Request $request): JsonResponse
    {
        $this->forgetConnection($this->currentUser($request));

        return $this->success(['url' => null]);
    }

    private function squareUrl(User $user): ?string
    {
        return SelectionPayload::fromArray($this->readConnection($user) ?? [])->url;
    }

    /** @return array<string, mixed> */
    private function widgetOrAbort(string $merchant, ?string $unit): array
    {
        try {
            return $this->client->widget($merchant, $unit);
        } catch (Throwable) {
            abort(502, 'Could not reach Square — please try again.');
        }
    }

    /**
     * @param  array{employeeId:string, displayName:string, jobTitle:?string, avatarUrl:?string, bio:?string}  $member
     * @return array{employeeId:string, displayName:string, jobTitle:?string, avatarUrl:?string}
     */
    private function memberSummary(array $member): array
    {
        return [
            'employeeId' => $member['employeeId'],
            'displayName' => $member['displayName'],
            'jobTitle' => $member['jobTitle'],
            'avatarUrl' => $member['avatarUrl'],
        ];
    }
}
