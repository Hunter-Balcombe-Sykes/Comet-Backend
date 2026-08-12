@extends('mail.layouts.partna')

@section('preheader', "New enquiry from {$enquiry->name}: {$enquiry->subject}")

@section('content')
    <h1 class="headline text-primary" style="margin: 0 0 16px 0; font-family:-apple-system,BlinkMacSystemFont,'SF Pro Display','Segoe UI',Roboto,sans-serif; font-size: 32px; font-weight: 600; line-height: 1.125; letter-spacing: -0.022em; color: #171717;">
        New enquiry
    </h1>

    <p class="body-text text-primary" style="margin: 0 0 24px 0; font-size: 17px; line-height: 1.47; color: #171717;">
        {{ $enquiry->name }} sent you a new enquiry through your Partna sitepage.
    </p>

    {{-- Details panel — Apple-style light grey card. Table-based + inline styles
         because Outlook drops padding/background on bare <div>s. --}}
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0;">
        <tr>
            <td style="background-color:#f2f2f2; border-radius:12px; padding: 20px 24px;">
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                    <tr>
                        <td style="padding: 0 0 16px 0;">
                            <div style="font-size:13px; line-height:1.4; color:#8f8f8f; margin:0 0 3px 0;">From</div>
                            <div style="font-size:16px; line-height:1.4; color:#171717; font-weight:500;">{{ $enquiry->name }}</div>
                            <div style="font-size:14px; line-height:1.5; color:#7d7d7d;">
                                <a href="mailto:{{ $enquiry->email }}" style="color:#1367fb; text-decoration:none;">{{ $enquiry->email }}</a>@if ($enquiry->phone) &nbsp;&middot;&nbsp; {{ $enquiry->phone }}@endif
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 0 0 16px 0;">
                            <div style="font-size:13px; line-height:1.4; color:#8f8f8f; margin:0 0 3px 0;">Subject</div>
                            <div style="font-size:16px; line-height:1.4; color:#171717;">{{ $enquiry->subject }}</div>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <div style="font-size:13px; line-height:1.4; color:#8f8f8f; margin:0 0 3px 0;">Message</div>
                            <div style="font-size:16px; line-height:1.5; color:#171717; white-space:pre-wrap;">{{ $enquiry->message }}</div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <x-mail.button :href="$dashboardUrl">View in your dashboard</x-mail.button>

    <p class="body-text text-secondary" style="margin: 32px 0 0 0; font-size: 13px; line-height: 1.5; color: #8f8f8f;">
        Submitted {{ $enquiry->created_at->format('j M Y, H:i') }} UTC. Reply to {{ $enquiry->name }} at
        <a href="mailto:{{ $enquiry->email }}" style="color: #1367fb; text-decoration: none;">{{ $enquiry->email }}</a>.
    </p>
@endsection

@section('footer_note', "You're receiving this because email notifications are on for your contact form.")
