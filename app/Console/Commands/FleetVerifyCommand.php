<?php

namespace App\Console\Commands;

use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

// Phase-0 run tooling (efficiency protocol, 2026-08-28): ONE compact table
// replacing the ad-hoc tinker round-trips every verification sweep used to
// take. Read-only. Staff/dev use via `cloud command:run`.
class FleetVerifyCommand extends Command
{
    protected $signature = 'fleet:verify {handles* : handle_lc values to check} {--http : Also check the live site over HTTPS}';

    protected $description = 'One-line-per-account fleet state: build, headshot, workplace, contact routing, (optionally) live HTTP.';

    public function handle(): int
    {
        $rows = [];
        foreach ($this->argument('handles') as $handle) {
            $handle = strtolower(trim((string) $handle));
            $user = User::query()->where('handle_lc', $handle)->first();
            if (! $user) {
                $rows[] = [$handle, 'MISSING', '-', '-', '-', '-', '-', '-'];

                continue;
            }

            $build = PreAccountBuild::query()->where('user_id', $user->id)->orderByDesc('created_at')->first();
            $site = Site::query()->where('user_id', $user->id)->first();

            $headshot = '-';
            $workplace = '-';
            $contact = '-';
            if ($site) {
                $shot = SiteMedia::query()->where('site_id', $site->id)
                    ->where('pool', SiteMedia::POOL_DESIGN)
                    ->where('purpose', SiteMedia::PURPOSE_HEADSHOT)
                    ->first();
                $headshot = $shot->processing_state ?? '-';
                $workplace = Workplace::query()->where('site_id', $site->id)->value('name') ?? '-';

                $block = Block::query()->where('user_id', $user->id)
                    ->where('block_group', Block::GROUP_SECTIONS)
                    ->where('block_type', 'contact')->first();
                if ($block) {
                    $email = (string) data_get($block->settings, 'notification_email', '');
                    $contact = ($block->is_enabled ? 'on' : 'off').($email !== '' ? ':'.$email : '');
                }
            }

            $http = '-';
            if ($this->option('http')) {
                try {
                    $http = (string) Http::timeout(10)->get("https://{$handle}.partna.au")->status();
                } catch (\Throwable) {
                    $http = 'ERR';
                }
            }

            $rows[] = [
                $handle,
                (string) $user->status,
                $build->build_state ?? '-',
                $build->failure_code ?? '-',
                $headshot,
                $workplace,
                $contact,
                $http,
            ];
        }

        $this->table(['handle', 'status', 'build', 'failure', 'headshot', 'workplace', 'contact', 'http'], $rows);

        return self::SUCCESS;
    }
}
