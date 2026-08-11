<?php

use App\Mail\BaseTransactionalMail;

// §52 architecture test — every concrete mailable under app/Mail/ extends
// BaseTransactionalMail so the canonical from/reply-to addresses and the
// X-Partna-Pipeline header land on every transactional message. Bypassing
// the base class breaks Resend bounce attribution and lets the from-address
// silently drift away from config('mail.from').
//
// Adding a new mailable that extends Illuminate\Mail\Mailable directly will
// fail this test. The fix is always: extend BaseTransactionalMail and call
// ->buildEnvelope() as the first step of your build() chain.
//
// The App\Mail\Branding namespace is exempt: it holds branding support
// value-objects (EmailBrand, EmailBrandDefaults, EmailPalette) and the
// ProEmailBrandResolver service — none are mailables; they model the
// per-message white-label brand that BaseTransactionalMail consumes.
// App\Mail\Support is exempt for the same reason (HtmlToText — the derived
// plain-text-part converter the base class and the notification trait share).

arch('every mailable extends BaseTransactionalMail')
    ->expect('App\Mail')
    ->toExtend(BaseTransactionalMail::class)
    ->ignoring([BaseTransactionalMail::class, 'App\Mail\Branding', 'App\Mail\Support']);
