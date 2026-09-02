<?php

namespace App\Jobs\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\FreshaStaffMatcher;
use App\Services\Platforms\Payloads\SelectionPayload;
use App\Services\Platforms\Registry\Platform;
use App\Services\Platforms\SquareBookingClient;
use App\Services\Platforms\SquareBookingPage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Square's half of Fresha's auto-select (2026-09-02): a partna account whose
 * Square link names no team member gets the one staff member whose name
 * matches theirs stamped onto the URL as team_member_id. The URL IS the
 * selection — rewriting it re-dates the ingest source (SourceProvisioner)
 * and the eager run lands only that member's services. A storewide-capable
 * account books the whole venue and is left alone; so is a link that
 * already names someone, and a roster with no single confident match.
 */
class SquareAutoSelectJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [30];

    public int $timeout = 60;

    public int $uniqueFor = 600;

    public function __construct(public readonly string $userId)
    {
        $this->onQueue(config('partna.queues.scraping', 'scraping'));
    }

    public function uniqueId(): string
    {
        return $this->userId.':square-auto-select';
    }

    public function handle(SquareBookingClient $client, FreshaStaffMatcher $matcher): void
    {
        $user = User::find($this->userId);
        if ($user === null || $user->isPendingDeletion()) {
            return;
        }
        if (AccountCapabilities::for($user)->can_book_storewide) {
            return;
        }
        $row = IntegrationConnection::query()
            ->where('user_id', $this->userId)
            ->where('platform', Platform::Square->value)
            ->active()
            ->first();
        if ($row === null) {
            return;
        }
        $url = SelectionPayload::fromArray((array) $row->payload)->url;
        if ($url === null) {
            return;
        }
        $parsed = SquareBookingPage::parseUrl($url);
        if ($parsed['merchant'] === null || $parsed['teamMember'] !== null) {
            return;
        }

        try {
            $doc = $client->widget($parsed['merchant'], $parsed['unit']);
        } catch (Throwable $e) {
            Log::info('square.auto_select.widget_unavailable', ['user_id' => $this->userId, 'error' => $e->getMessage()]);

            return;
        }
        $team = SquareBookingPage::team($doc);
        $match = $matcher->matchWithTier($user, $team);
        if ($match['employeeId'] === null) {
            Log::info('square.auto_select.no_match', ['user_id' => $this->userId, 'team' => count($team)]);

            return;
        }
        $member = collect($team)->firstWhere('employeeId', $match['employeeId']);
        if ($member === null) {
            return;
        }

        $row->payload = [
            ...(array) $row->payload,
            'url' => SquareBookingPage::bookingUrl($parsed['merchant'], $parsed['unit'] ?? SquareBookingPage::unitToken($doc), $member['employeeId']),
            'teamMember' => [
                'employeeId' => $member['employeeId'],
                'displayName' => $member['displayName'],
                'jobTitle' => $member['jobTitle'],
                'avatarUrl' => $member['avatarUrl'],
            ],
            'autoSelected' => true,
            'matchTier' => $match['tier'],
        ];
        $row->save();

        Log::info('square.auto_select.stamped', [
            'user_id' => $this->userId,
            'employee_id' => $member['employeeId'],
            'tier' => $match['tier'],
        ]);
    }
}
