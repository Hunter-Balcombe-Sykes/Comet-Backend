<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>New Partna feedback</title></head>
<body style="font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; font-size: 14px; color: #111; line-height: 1.5;">
    <h2 style="margin: 0 0 16px;">New {{ $feedback->kind }} feedback</h2>

    <p style="margin: 0 0 8px;"><strong>Kind:</strong> {{ $feedback->kind }}</p>
    @if ($feedback->severity)
        <p style="margin: 0 0 8px;"><strong>Severity:</strong> {{ $feedback->severity }}</p>
    @endif
    <p style="margin: 0 0 8px;"><strong>Submitted:</strong> {{ $feedback->created_at->format('j M Y H:i') }} UTC</p>

    <hr style="border: none; border-top: 1px solid #ddd; margin: 16px 0;">

    <p style="margin: 0 0 8px;"><strong>From:</strong>
        @if ($userEmail)
            {{ $userEmail }}
        @else
            <em>(account email unavailable)</em>
        @endif
    </p>
    @if ($feedback->reply_email && $feedback->reply_email !== $userEmail)
        <p style="margin: 0 0 8px;"><strong>Reply to:</strong> {{ $feedback->reply_email }}</p>
    @endif
    <p style="margin: 0 0 8px;"><strong>User ID:</strong> <code>{{ $feedback->user_id ?? 'null' }}</code></p>
    <p style="margin: 0 0 8px;"><strong>Feedback ID:</strong> <code>{{ $feedback->id }}</code></p>

    <hr style="border: none; border-top: 1px solid #ddd; margin: 16px 0;">

    <p style="margin: 0 0 8px;"><strong>Message:</strong></p>
    <p style="white-space: pre-wrap; margin: 0 0 16px;">{{ $feedback->message }}</p>

    <hr style="border: none; border-top: 1px solid #ddd; margin: 16px 0;">

    <h3 style="margin: 0 0 12px; font-size: 14px;">Debugging context</h3>
    @if ($feedback->page_url)
        <p style="margin: 0 0 4px;"><strong>Page:</strong> {{ $feedback->page_url }}</p>
    @endif
    @if ($feedback->app_version)
        <p style="margin: 0 0 4px;"><strong>App version:</strong> {{ $feedback->app_version }}</p>
    @endif
    @if ($feedback->viewport)
        <p style="margin: 0 0 4px;"><strong>Viewport:</strong> {{ $feedback->viewport }}</p>
    @endif
    @if ($feedback->request_id)
        <p style="margin: 0 0 4px;"><strong>Request ID:</strong> <code>{{ $feedback->request_id }}</code></p>
    @endif
    @if ($feedback->user_agent)
        <p style="margin: 0 0 4px; color: #666; font-size: 12px;"><strong>User agent:</strong> {{ $feedback->user_agent }}</p>
    @endif
</body>
</html>
