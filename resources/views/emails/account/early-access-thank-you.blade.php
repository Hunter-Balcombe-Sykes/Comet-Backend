@extends('mail.layouts.partna')

@section('preheader', "You're on the Partna early access list.")

@section('content')
    <h1 class="headline text-primary" style="margin: 0 0 16px 0; font-family:-apple-system,BlinkMacSystemFont,'SF Pro Display','Segoe UI',Roboto,sans-serif; font-size: 32px; font-weight: 600; line-height: 1.125; letter-spacing: -0.022em; color: #171717;">
        You're on the list
    </h1>

    <p class="body-text text-primary" style="margin: 0 0 16px 0; font-size: 17px; line-height: 1.47; color: #171717;">
        Thanks for signing up for Partna early access with {{ $recipientEmail }}.
    </p>

    <p class="body-text text-primary" style="margin: 0 0 24px 0; font-size: 17px; line-height: 1.47; color: #171717;">
        We're inviting people in waves. When it's your turn you'll get an email from us with a link to set up your site — no need to do anything until then.
    </p>

    <p class="body-text text-secondary" style="margin: 24px 0 0 0; font-size: 14px; line-height: 1.5; color: #7d7d7d;">
        If you didn't sign up for Partna early access, you can safely ignore this email.
    </p>
@endsection

@section('footer_note', "You're receiving this because this address joined the Partna early access list.")
