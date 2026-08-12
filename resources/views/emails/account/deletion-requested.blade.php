@extends('mail.layouts.partna')

@section('title', 'Confirm your account deletion')
@section('preheader', 'Confirm your account deletion request — link expires in 24 hours.')

@section('content')
    <h1 class="headline text-primary" style="margin: 0 0 16px 0; font-family:-apple-system,BlinkMacSystemFont,'SF Pro Display','Segoe UI',Roboto,sans-serif; font-size: 32px; font-weight: 600; line-height: 1.125; letter-spacing: -0.022em; color: #171717;">
        Confirm your account deletion
    </h1>

    <p class="body-text text-primary" style="margin: 0 0 16px 0; font-size: 17px; line-height: 1.47; color: #171717;">
        Hi {{ $displayName }},
    </p>

    <p class="body-text text-primary" style="margin: 0 0 24px 0; font-size: 17px; line-height: 1.47; color: #171717;">
        We received a request to delete your Partna account. Tap the button below to confirm.
    </p>

    <x-mail.button :href="$confirmationUrl">Confirm deletion</x-mail.button>

    <x-mail.fine-print :url="$confirmationUrl">
        This link expires in 24 hours. If you didn't request this, ignore this email — your account stays active.
    </x-mail.fine-print>
@endsection

@section('footer_note', 'This is a transactional email related to your account security.')
