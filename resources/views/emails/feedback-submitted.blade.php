@extends('mail.layouts.partna')

@section('title', 'New Partna feedback')
@section('preheader', "New {$feedback->kind} feedback submitted.")

@section('content')
    <h1 class="headline text-primary" style="margin: 0 0 24px 0; font-family:-apple-system,BlinkMacSystemFont,'SF Pro Display','Segoe UI',Roboto,sans-serif; font-size: 28px; font-weight: 600; line-height: 1.15; letter-spacing: -0.018em; color: #1d1d1f;">
        New {{ $feedback->kind }} feedback
    </h1>

    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 20px 0;">
        <tr>
            <td style="padding: 4px 0; font-size: 14px; line-height: 1.5; color: #6e6e73; width: 140px;">Kind</td>
            <td style="padding: 4px 0; font-size: 14px; line-height: 1.5; color: #1d1d1f;">{{ $feedback->kind }}</td>
        </tr>
        @if ($feedback->severity)
            <tr>
                <td style="padding: 4px 0; font-size: 14px; line-height: 1.5; color: #6e6e73;">Severity</td>
                <td style="padding: 4px 0; font-size: 14px; line-height: 1.5; color: #1d1d1f;">{{ $feedback->severity }}</td>
            </tr>
        @endif
        <tr>
            <td style="padding: 4px 0; font-size: 14px; line-height: 1.5; color: #6e6e73;">Submitted</td>
            <td style="padding: 4px 0; font-size: 14px; line-height: 1.5; color: #1d1d1f;">{{ $feedback->created_at->format('j M Y H:i') }} UTC</td>
        </tr>
        <tr>
            <td style="padding: 4px 0; font-size: 14px; line-height: 1.5; color: #6e6e73;">From</td>
            <td style="padding: 4px 0; font-size: 14px; line-height: 1.5; color: #1d1d1f;">
                @if ($userEmail)
                    {{ $userEmail }}
                @else
                    <em>(account email unavailable)</em>
                @endif
            </td>
        </tr>
        @if ($feedback->reply_email && $feedback->reply_email !== $userEmail)
            <tr>
                <td style="padding: 4px 0; font-size: 14px; line-height: 1.5; color: #6e6e73;">Reply to</td>
                <td style="padding: 4px 0; font-size: 14px; line-height: 1.5; color: #1d1d1f;">{{ $feedback->reply_email }}</td>
            </tr>
        @endif
        <tr>
            <td style="padding: 4px 0; font-size: 14px; line-height: 1.5; color: #6e6e73;">User ID</td>
            <td style="padding: 4px 0; font-size: 13px; line-height: 1.5; color: #1d1d1f; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;">{{ $feedback->user_id ?? 'null' }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 0; font-size: 14px; line-height: 1.5; color: #6e6e73;">Feedback ID</td>
            <td style="padding: 4px 0; font-size: 13px; line-height: 1.5; color: #1d1d1f; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;">{{ $feedback->id }}</td>
        </tr>
    </table>

    <p class="body-text text-secondary" style="margin: 0 0 8px 0; font-size: 13px; font-weight: 600; line-height: 1.5; color: #6e6e73; text-transform: uppercase; letter-spacing: 0.04em;">
        Message
    </p>
    <p class="body-text text-primary" style="margin: 0 0 24px 0; font-size: 16px; line-height: 1.5; color: #1d1d1f; white-space: pre-wrap;">{{ $feedback->message }}</p>

    <p class="body-text text-secondary" style="margin: 0 0 8px 0; font-size: 13px; font-weight: 600; line-height: 1.5; color: #6e6e73; text-transform: uppercase; letter-spacing: 0.04em;">
        Debugging context
    </p>
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
        @if ($feedback->page_url)
            <tr>
                <td style="padding: 3px 0; font-size: 13px; line-height: 1.5; color: #6e6e73; width: 140px;">Page</td>
                <td style="padding: 3px 0; font-size: 13px; line-height: 1.5; color: #1d1d1f;">{{ $feedback->page_url }}</td>
            </tr>
        @endif
        @if ($feedback->app_version)
            <tr>
                <td style="padding: 3px 0; font-size: 13px; line-height: 1.5; color: #6e6e73;">App version</td>
                <td style="padding: 3px 0; font-size: 13px; line-height: 1.5; color: #1d1d1f;">{{ $feedback->app_version }}</td>
            </tr>
        @endif
        @if ($feedback->viewport)
            <tr>
                <td style="padding: 3px 0; font-size: 13px; line-height: 1.5; color: #6e6e73;">Viewport</td>
                <td style="padding: 3px 0; font-size: 13px; line-height: 1.5; color: #1d1d1f;">{{ $feedback->viewport }}</td>
            </tr>
        @endif
        @if ($feedback->request_id)
            <tr>
                <td style="padding: 3px 0; font-size: 13px; line-height: 1.5; color: #6e6e73;">Request ID</td>
                <td style="padding: 3px 0; font-size: 13px; line-height: 1.5; color: #1d1d1f; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;">{{ $feedback->request_id }}</td>
            </tr>
        @endif
        @if ($feedback->user_agent)
            <tr>
                <td style="padding: 3px 0; font-size: 12px; line-height: 1.5; color: #86868b;">User agent</td>
                <td style="padding: 3px 0; font-size: 12px; line-height: 1.5; color: #86868b;">{{ $feedback->user_agent }}</td>
            </tr>
        @endif
    </table>
@endsection

@section('footer_note', 'Internal team notification — Partna feedback pipeline.')
