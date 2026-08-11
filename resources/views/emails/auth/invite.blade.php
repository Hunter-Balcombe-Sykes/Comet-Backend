@extends('mail.layouts.partna')

@section('preheader', 'You\'ve been invited to join Partna — the invite expires in 7 days.')

@section('content')
    <h1 class="headline text-primary" style="margin: 0 0 16px 0; font-family:-apple-system,BlinkMacSystemFont,'SF Pro Display','Segoe UI',Roboto,sans-serif; font-size: 32px; font-weight: 600; line-height: 1.125; letter-spacing: -0.022em; color: #171717;">
        You're invited to Partna
    </h1>

    <p class="body-text text-primary" style="margin: 0 0 16px 0; font-size: 17px; line-height: 1.47; color: #171717;">
        Hi,
    </p>

    <p class="body-text text-primary" style="margin: 0 0 24px 0; font-size: 17px; line-height: 1.47; color: #171717;">
        You've been invited to join Partna — an account has been set up for this address ({{ $recipientEmail }}). Tap the button below to accept the invite and finish setting it up.
    </p>

    <x-mail.button :href="$verifyUrl">Accept invite</x-mail.button>

    <x-mail.fine-print :url="$verifyUrl">
        This invite expires in 7 days. If you weren't expecting it, ignore this email.
    </x-mail.fine-print>
@endsection

@section('footer_note', 'You\'re receiving this because someone invited you to Partna.')
