@extends('mail.layouts.partna')

@section('preheader', 'Your one-time Partna sign-in link — it expires in 1 hour.')

@section('content')
    <h1 class="headline text-primary" style="margin: 0 0 16px 0; font-family:-apple-system,BlinkMacSystemFont,'SF Pro Display','Segoe UI',Roboto,sans-serif; font-size: 32px; font-weight: 600; line-height: 1.125; letter-spacing: -0.022em; color: #171717;">
        Sign in to Partna
    </h1>

    <p class="body-text text-primary" style="margin: 0 0 16px 0; font-size: 17px; line-height: 1.47; color: #171717;">
        {{ $displayName ? "Hi {$displayName}," : 'Hi,' }}
    </p>

    <p class="body-text text-primary" style="margin: 0 0 24px 0; font-size: 17px; line-height: 1.47; color: #171717;">
        Tap the button below to sign in to your Partna account ({{ $recipientEmail }}). You'll be taken straight to your dashboard — no password needed.
    </p>

    <x-mail.button :href="$verifyUrl">Sign in to Partna</x-mail.button>

    <x-mail.fine-print :url="$verifyUrl">
        This link expires in 1 hour and works once. If you didn't ask to sign in, ignore this email.
    </x-mail.fine-print>
@endsection

@section('footer_note', 'This is a transactional email related to your account access.')
