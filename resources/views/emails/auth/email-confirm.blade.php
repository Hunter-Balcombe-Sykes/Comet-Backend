@extends('mail.layouts.partna')

@section('preheader', 'Open this email to get your verification code.')

@section('content')
    <h1 class="headline text-primary" style="margin: 0 0 16px 0; font-family:-apple-system,BlinkMacSystemFont,'SF Pro Display','Segoe UI',Roboto,sans-serif; font-size: 32px; font-weight: 600; line-height: 1.125; letter-spacing: -0.022em; color: #1d1d1f;">
        {{ $displayName ? "Hi {$displayName}," : 'Verify your email' }}
    </h1>

    <p class="body-text text-primary" style="margin: 0 0 24px 0; font-size: 17px; line-height: 1.47; color: #1d1d1f;">
        Enter the code below in your Partna sign-up to confirm {{ $recipientEmail }} is yours.
    </p>

    {{-- 6-digit code, Apple-style large monospace.
         table+background colour because Outlook + Gmail Android both
         occasionally drop padding on bare <div>s for non-button content. --}}
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin: 8px 0 8px 0;">
        <tr>
            <td align="center" style="background-color:#f5f5f7; border-radius:12px; padding: 20px 28px;">
                <div style="font-family: 'SF Mono', Menlo, Consolas, 'Courier New', monospace; font-size: 36px; font-weight: 600; line-height: 1; letter-spacing: 0.18em; color:#1d1d1f;">
                    {{ $code }}
                </div>
            </td>
        </tr>
    </table>

    <p class="body-text text-secondary" style="margin: 24px 0 0 0; font-size: 14px; line-height: 1.5; color: #6e6e73;">
        The code expires in 1 hour. If you didn't try to sign up for Partna, you can safely ignore this email.
    </p>
@endsection

@section('footer_note', "You're receiving this because someone tried to sign up to Partna with this address.")
