<?php

namespace App\Services\User;

use App\Models\Core\User\User;
use Illuminate\Support\Str;

// Extracted verbatim from BootstrapRequest::generateHandleFromDisplayName so the
// pre-account build path can allocate handles without a Form Request in scope.
// Behavior contract: slug, 'professional' fallback, BARE-integer collision suffix
// (jane-doe1 — distinct from the subdomain's hyphenated -1 style, deliberately).
class HandleAllocator
{
    /** @return array{handle: string, handle_lc: string} */
    public function allocate(string $seed): array
    {
        $base = Str::slug($seed);
        if ($base === '' || $base === '-') {
            $base = 'professional';
        }

        $handle = $base;
        $attempt = 1;
        while (User::query()->where('handle_lc', strtolower($handle))->exists()) {
            $handle = $base.$attempt;
            $attempt++;
        }

        return ['handle' => $handle, 'handle_lc' => strtolower($handle)];
    }
}
