<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;

class HandleAliasExpiringMail extends BaseTransactionalMail implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly object $alias,
        public readonly string $bucket  // 't3' or 't1'
    ) {}

    public function build(): self
    {
        $days = $this->bucket === 't3' ? 3 : 1;

        return $this->buildEnvelope()
            ->subject("Your old handle \"{$this->alias->handle}\" releases in {$days} day(s)")
            ->view('mail.handle-alias-expiring');
    }
}
