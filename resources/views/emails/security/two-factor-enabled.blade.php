@extends('mail.layouts.partna')

@section('preheader', 'Two-factor authentication is now protecting your Partna account.')

@section('content')
    <h1 class="headline text-primary" style="margin: 0 0 16px 0; font-family:-apple-system,BlinkMacSystemFont,'SF Pro Display','Segoe UI',Roboto,sans-serif; font-size: 32px; font-weight: 600; line-height: 1.125; letter-spacing: -0.022em; color: #171717;">
        Two-factor authentication is on
    </h1>

    <p class="body-text text-primary" style="margin: 0 0 16px 0; font-size: 17px; line-height: 1.47; color: #171717;">
        {{ $displayName ? "Hi {$displayName}," : 'Hi,' }}
    </p>

    <p class="body-text text-primary" style="margin: 0 0 24px 0; font-size: 17px; line-height: 1.47; color: #171717;">
        Two-factor authentication was just turned on for your Partna account ({{ $recipientEmail }}). From now on, logging in takes your password and a code from your authenticator app.
    </p>

    <x-mail.fine-print>
        If you didn't do this, change your password now — someone may have access to your account.
    </x-mail.fine-print>
@endsection

@section('footer_note', 'This is a transactional email related to your account security.')
