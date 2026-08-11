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
        $when = $this->bucket === 't3' ? 'in 3 days' : 'tomorrow';

        return $this->buildEnvelope()
            ->subject("Your old handle \"{$this->alias->handle}\" releases {$when}")
            ->view('emails.account.handle-alias-expiring');
    }
}
