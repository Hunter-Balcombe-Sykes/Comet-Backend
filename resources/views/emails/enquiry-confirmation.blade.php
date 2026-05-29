<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>We received your enquiry</title></head>
<body style="font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; font-size: 14px; color: #111; line-height: 1.5;">
    <h2 style="margin: 0 0 16px;">Thanks{{ $visitorName !== '' ? ', '.$visitorName : '' }} — we've got your enquiry</h2>

    <p style="margin: 0 0 12px;">{{ $proDisplayName }} has received your message about &ldquo;{{ $subject }}&rdquo; and will get back to you soon.</p>

    <p style="margin: 0 0 12px;">You can reply directly to this email if you need to add anything.</p>

    <p style="margin: 16px 0 0;">
        <a href="{{ $siteUrl }}" style="color: #3a6efc;">Visit {{ $proDisplayName }}'s page</a>
    </p>
</body>
</html>
