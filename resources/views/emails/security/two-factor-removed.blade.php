@extends('mail.layouts.partna')

@section('preheader', 'Two-factor authentication was just removed from your Partna account.')

@section('content')
    <h1 class="headline text-primary" style="margin: 0 0 16px 0; font-family:-apple-system,BlinkMacSystemFont,'SF Pro Display','Segoe UI',Roboto,sans-serif; font-size: 32px; font-weight: 600; line-height: 1.125; letter-spacing: -0.022em; color: #171717;">
        Two-factor authentication was removed
    </h1>

    <p class="body-text text-primary" style="margin: 0 0 16px 0; font-size: 17px; line-height: 1.47; color: #171717;">
        {{ $displayName ? "Hi {$displayName}," : 'Hi,' }}
    </p>

    <p class="body-text text-primary" style="margin: 0 0 24px 0; font-size: 17px; line-height: 1.47; color: #171717;">
        Two-factor authentication was just removed from your Partna account ({{ $recipientEmail }}). Your account is now protected by your password alone.
    </p>

    <x-mail.fine-print>
        If this was you, no action is needed. If you didn't do this, change your password now and re-enable two-factor authentication from Settings → Security — someone may have access to your account.
    </x-mail.fine-print>
@endsection

@section('footer_note', 'This is a transactional email related to your account security.')
