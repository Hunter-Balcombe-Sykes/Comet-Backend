@extends('mail.layouts.partna')

@section('preheader', 'Your Partna site is ready.')

@section('content')
    <h1 class="headline text-primary" style="margin: 0 0 16px 0; font-family:-apple-system,BlinkMacSystemFont,'SF Pro Display','Segoe UI',Roboto,sans-serif; font-size: 32px; font-weight: 600; line-height: 1.125; letter-spacing: -0.022em; color: #171717;">
        Your Partna site is ready
    </h1>

    <p class="body-text text-primary" style="margin: 0 0 16px 0; font-size: 17px; line-height: 1.47; color: #171717;">
        We've built a site for you. Claim it to make it yours and take control of your page.
    </p>

    <x-mail.button :href="$claimUrl">Claim your site</x-mail.button>

    <x-mail.fine-print :url="$claimUrl">
        This invite is personal to this email address. If you weren't expecting it, ignore this email.
    </x-mail.fine-print>
@endsection

@section('footer_note', "You're receiving this because a Partna site was built for this email address.")
