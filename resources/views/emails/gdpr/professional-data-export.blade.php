@extends('mail.layouts.partna')

@section('title', 'Your Partna data export')
@section('preheader', 'Your Partna data export is ready to download.')

@section('content')
    @if ($isStaff)
        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 24px 0;">
            <tr>
                <td style="padding: 12px 16px; background-color: #fff7e6; border: 1px solid #ffd591; border-radius: 8px; font-size: 14px; line-height: 1.5; color: #1d1d1f;">
                    <strong>Staff notice:</strong> this export contains customer PII collected by <strong>{{ $professionalHandle }}</strong>. Handle in accordance with the staff data-handling SOP. Do not forward this link.
                </td>
            </tr>
        </table>
    @endif

    <h1 class="headline text-primary" style="margin: 0 0 16px 0; font-family:-apple-system,BlinkMacSystemFont,'SF Pro Display','Segoe UI',Roboto,sans-serif; font-size: 32px; font-weight: 600; line-height: 1.125; letter-spacing: -0.022em; color: #1d1d1f;">
        Your Partna data export is ready
    </h1>

    <p class="body-text text-primary" style="margin: 0 0 24px 0; font-size: 17px; line-height: 1.47; color: #1d1d1f;">
        The data export for <strong>{{ $professionalHandle }}</strong> has been prepared.
    </p>

    <x-mail.button :href="$signedUrl">Download the export (.zip)</x-mail.button>

    <p class="body-text text-primary" style="margin: 32px 0 16px 0; font-size: 17px; line-height: 1.47; color: #1d1d1f;">
        This link is valid for <strong>{{ $ttlDays }} days</strong>. The file contains roughly <strong>{{ number_format($totalRecords) }}</strong> records across your profile and enquiry history.
    </p>

    <p class="body-text text-primary" style="margin: 0 0 16px 0; font-size: 17px; line-height: 1.47; color: #1d1d1f;">
        <strong>What's inside:</strong> a <code>data.json</code> file with the full machine-readable export, plus per-table CSVs (<code>customers.csv</code>, <code>enquiries.csv</code>) for the tables you'd typically open in Excel or Numbers.
    </p>

    @unless ($isStaff)
        <p class="body-text text-primary" style="margin: 0 0 16px 0; font-size: 17px; line-height: 1.47; color: #1d1d1f;">
            If you collected customer information through Partna, this export includes it. You're responsible for handling that information in accordance with applicable privacy law.
        </p>
    @endunless

    <p class="body-text text-secondary" style="margin: 0; font-size: 14px; line-height: 1.5; color: #6e6e73;">
        If you didn't request this export, reply to this email — we'll investigate.
    </p>
@endsection

@section('footer_note', "This message contains a link to a file stored on Cloudflare R2. The link expires in {$ttlDays} days; the file itself is automatically deleted after 30 days.")
