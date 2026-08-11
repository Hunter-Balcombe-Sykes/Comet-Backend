@extends('mail.layouts.partna')

@section('title', 'Your account has been suspended')
@section('preheader', 'Your Partna account has been suspended. You may submit an appeal from your dashboard.')

@section('content')
    <h1 class="headline text-primary" style="margin: 0 0 16px 0; font-family:-apple-system,BlinkMacSystemFont,'SF Pro Display','Segoe UI',Roboto,sans-serif; font-size: 32px; font-weight: 600; line-height: 1.125; letter-spacing: -0.022em; color: #171717;">
        Your account has been suspended
    </h1>

    <p class="body-text text-primary" style="margin: 0 0 16px 0; font-size: 17px; line-height: 1.47; color: #171717;">
        Your Partna account has been suspended for the following reason:
    </p>

    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 24px 0;">
        <tr>
            <td style="padding: 12px 16px; background-color: #f2f2f2; border-radius: 8px; font-size: 15px; line-height: 1.5; color: #171717;">
                {{ $decision->reason ?? 'A violation of our community standards.' }}
            </td>
        </tr>
    </table>

    <p class="body-text text-secondary" style="margin: 0; font-size: 14px; line-height: 1.5; color: #7d7d7d;">
        You may submit an appeal through your account dashboard.
    </p>
@endsection

@section('footer_note', 'Sent by Partna Trust & Safety.')
