@extends('mail.layouts.partna')

@section('preheader', 'Confirm your new email address for Partna — link expires in 1 hour.')

@section('content')
    <h1 class="headline text-primary" style="margin: 0 0 16px 0; font-family:-apple-system,BlinkMacSystemFont,'SF Pro Display','Segoe UI',Roboto,sans-serif; font-size: 32px; font-weight: 600; line-height: 1.125; letter-spacing: -0.022em; color: #171717;">
        Confirm your new email
    </h1>

    <p class="body-text text-primary" style="margin: 0 0 16px 0; font-size: 17px; line-height: 1.47; color: #171717;">
        {{ $displayName ? "Hi {$displayName}," : 'Hi,' }}
    </p>

    <p class="body-text text-primary" style="margin: 0 0 24px 0; font-size: 17px; line-height: 1.47; color: #171717;">
        You requested an email address change on your Partna account. Tap the button below to confirm {{ $recipientEmail }} as your new address.
    </p>

    <x-mail.button :href="$verifyUrl">Confirm email change</x-mail.button>

    <x-mail.fine-print :url="$verifyUrl">
        This link expires in 1 hour and works once. If you didn't request this change, ignore this email — your current address stays active — and consider changing your password, since someone may have access to your account.
    </x-mail.fine-print>
@endsection

@section('footer_note', 'This is a transactional email related to your account security.')
