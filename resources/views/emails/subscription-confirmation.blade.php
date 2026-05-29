<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>You're subscribed</title></head>
<body style="font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; font-size: 14px; color: #111; line-height: 1.5;">
    <h2 style="margin: 0 0 16px;">You're subscribed{{ $visitorName ? ', '.$visitorName : '' }}</h2>

    <p style="margin: 0 0 12px;">Thanks for joining {{ $proDisplayName }}'s list. You'll hear about news and updates straight from them.</p>

    <p style="margin: 16px 0 12px;">
        <a href="{{ $siteUrl }}" style="color: #3a6efc;">Visit {{ $proDisplayName }}'s page</a>
    </p>

    <hr style="border: none; border-top: 1px solid #ddd; margin: 16px 0;">

    <p style="margin: 0; color: #666; font-size: 12px;">
        Didn't sign up, or changed your mind?
        <a href="{{ $unsubscribeUrl }}" style="color: #666;">Unsubscribe</a>.
    </p>
</body>
</html>
