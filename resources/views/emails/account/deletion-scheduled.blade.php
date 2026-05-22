<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your account is scheduled for deletion</title>
    <style>
        body { font-family: sans-serif; background: #f5f5f5; margin: 0; padding: 0; }
        .container { max-width: 560px; margin: 40px auto; background: #fff; border-radius: 8px; padding: 32px; }
        h2 { margin-top: 0; font-size: 20px; color: #111; }
        p { color: #444; line-height: 1.6; }
        .btn { display: inline-block; margin-top: 16px; padding: 12px 24px; background: #111; color: #fff; text-decoration: none; border-radius: 6px; font-size: 14px; }
        .date { font-weight: 600; color: #111; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Your account is scheduled for deletion</h2>
        <p>Hi {{ $displayName }},</p>
        <p>Your Partna account will be permanently deleted on <span class="date">{{ $deletesAt }}</span>.</p>
        <p>Your account is now in read-only mode and your public profile page is offline. You can still log in to cancel the deletion at any time during this window.</p>
        <a href="{{ $cancelUrl }}" class="btn">Cancel deletion</a>
    </div>
</body>
</html>
