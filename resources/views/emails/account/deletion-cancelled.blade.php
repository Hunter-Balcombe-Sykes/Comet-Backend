@extends('mail.layouts.partna')

@section('title', 'Your account deletion has been cancelled')
@section('preheader', 'Your account deletion has been cancelled — your account and profile are active again.')

@section('content')
    <h1 class="headline text-primary" style="margin: 0 0 16px 0; font-family:-apple-system,BlinkMacSystemFont,'SF Pro Display','Segoe UI',Roboto,sans-serif; font-size: 32px; font-weight: 600; line-height: 1.125; letter-spacing: -0.022em; color: #171717;">
        Your account deletion has been cancelled
    </h1>

    <p class="body-text text-primary" style="margin: 0 0 16px 0; font-size: 17px; line-height: 1.47; color: #171717;">
        Hi {{ $displayName }},
    </p>

    <p class="body-text text-primary" style="margin: 0 0 16px 0; font-size: 17px; line-height: 1.47; color: #171717;">
        Your Partna account deletion has been cancelled. Your account is active again and your public profile page is back online.
    </p>

    <p class="body-text text-secondary" style="margin: 0; font-size: 14px; line-height: 1.5; color: #7d7d7d;">
        If you didn't request this, please contact support immediately.
    </p>
@endsection

@section('footer_note', 'This is a transactional email related to your account security.')
