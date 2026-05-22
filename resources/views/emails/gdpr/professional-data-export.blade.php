<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Your Partna data export</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #111; max-width: 640px; margin: 0 auto; padding: 24px;">
    @if ($isStaff)
        <div style="background: #fff7e6; border: 1px solid #ffd591; padding: 12px 16px; border-radius: 6px; margin-bottom: 24px;">
            <strong>Staff notice:</strong> this export contains customer PII collected by <strong>{{ $professionalHandle }}</strong>. Handle in accordance with the staff data-handling SOP. Do not forward this link.
        </div>
    @endif

    <h2 style="margin-top: 0;">Your Partna data export is ready</h2>

    <p>The data export for <strong>{{ $professionalHandle }}</strong> has been prepared.</p>

    <p><a href="{{ $signedUrl }}" style="display: inline-block; background: #111; color: #fff; padding: 12px 20px; border-radius: 6px; text-decoration: none;">Download the export (.zip)</a></p>

    <p>This link is valid for <strong>{{ $ttlDays }} days</strong>. The file contains roughly <strong>{{ number_format($totalRecords) }}</strong> records across your profile and enquiry history.</p>

    <p><strong>What's inside:</strong> a <code>data.json</code> file with the full machine-readable export, plus per-table CSVs (<code>customers.csv</code>, <code>enquiries.csv</code>) for the tables you'd typically open in Excel or Numbers.</p>

    @unless ($isStaff)
        <p>If you collected customer information through Partna, this export includes it. You're responsible for handling that information in accordance with applicable privacy law.</p>
    @endunless

    <p>If you didn't request this export, reply to this email — we'll investigate.</p>

    <p>— Partna</p>

    <hr style="border: none; border-top: 1px solid #eee; margin: 32px 0 16px;">

    <p style="font-size: 12px; color: #666;">This message contains a link to a file stored on Cloudflare R2. The link expires in {{ $ttlDays }} days; the file itself is automatically deleted after 30 days.</p>
</body>
</html>
