@extends('mail.layouts.partna')

@section('title', 'New Partna feedback')
@section('preheader', "New {$feedback->kind} feedback submitted.")

@section('content')
    <h1 class="headline text-primary" style="margin: 0 0 24px 0; font-family:-apple-system,BlinkMacSystemFont,'SF Pro Display','Segoe UI',Roboto,sans-serif; font-size: 28px; font-weight: 600; line-height: 1.15; letter-spacing: -0.018em; color: #171717;">
        New {{ $feedback->kind }} feedback
    </h1>

    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 20px 0;">
        <tr>
            <td style="padding: 4px 0; font-size: 14px; line-height: 1.5; color: #7d7d7d; width: 140px;">Kind</td>
            <td style="padding: 4px 0; font-size: 14px; line-height: 1.5; color: #171717;">{{ $feedback->kind }}</td>
        </tr>
        @if ($feedback->severity)
            <tr>
                <td style="padding: 4px 0; font-size: 14px; line-height: 1.5; color: #7d7d7d;">Severity</td>
                <td style="padding: 4px 0; font-size: 14px; line-height: 1.5; color: #171717;">{{ $feedback->severity }}</td>
            </tr>
        @endif
        <tr>
            <td style="padding: 4px 0; font-size: 14px; line-height: 1.5; color: #7d7d7d;">Submitted</td>
            <td style="padding: 4px 0; font-size: 14px; line-height: 1.5; color: #171717;">{{ $feedback->created_at->format('j M Y H:i') }} UTC</td>
        </tr>
        <tr>
            <td style="padding: 4px 0; font-size: 14px; line-height: 1.5; color: #7d7d7d;">From</td>
            <td style="padding: 4px 0; font-size: 14px; line-height: 1.5; color: #171717;">
                @if ($userEmail)
                    {{ $userEmail }}
                @else
                    <em>(account email unavailable)</em>
                @endif
            </td>
        </tr>
        @if ($feedback->reply_email && $feedback->reply_email !== $userEmail)
            <tr>
                <td style="padding: 4px 0; font-size: 14px; line-height: 1.5; color: #7d7d7d;">Reply to</td>
                <td style="padding: 4px 0; font-size: 14px; line-height: 1.5; color: #171717;">{{ $feedback->reply_email }}</td>
            </tr>
        @endif
        <tr>
            <td style="padding: 4px 0; font-size: 14px; line-height: 1.5; color: #7d7d7d;">User ID</td>
            <td style="padding: 4px 0; font-size: 13px; line-height: 1.5; color: #171717; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;">{{ $feedback->user_id ?? 'null' }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 0; font-size: 14px; line-height: 1.5; color: #7d7d7d;">Feedback ID</td>
            <td style="padding: 4px 0; font-size: 13px; line-height: 1.5; color: #171717; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;">{{ $feedback->id }}</td>
        </tr>
    </table>

    <p class="body-text text-secondary" style="margin: 0 0 8px 0; font-size: 13px; font-weight: 600; line-height: 1.5; color: #7d7d7d; text-transform: uppercase; letter-spacing: 0.04em;">
        Message
    </p>
    <p class="body-text text-primary" style="margin: 0 0 24px 0; font-size: 16px; line-height: 1.5; color: #171717; white-space: pre-wrap;">{{ $feedback->message }}</p>

    <p class="body-text text-secondary" style="margin: 0 0 8px 0; font-size: 13px; font-weight: 600; line-height: 1.5; color: #7d7d7d; text-transform: uppercase; letter-spacing: 0.04em;">
        Debugging context
    </p>
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
        @if ($feedback->page_url)
            <tr>
                <td style="padding: 3px 0; font-size: 13px; line-height: 1.5; color: #7d7d7d; width: 140px;">Page</td>
                <td style="padding: 3px 0; font-size: 13px; line-height: 1.5; color: #171717;">{{ $feedback->page_url }}</td>
            </tr>
        @endif
        @if ($feedback->app_version)
            <tr>
                <td style="padding: 3px 0; font-size: 13px; line-height: 1.5; color: #7d7d7d;">App version</td>
                <td style="padding: 3px 0; font-size: 13px; line-height: 1.5; color: #171717;">{{ $feedback->app_version }}</td>
            </tr>
        @endif
        @if ($feedback->viewport)
            <tr>
                <td style="padding: 3px 0; font-size: 13px; line-height: 1.5; color: #7d7d7d;">Viewport</td>
                <td style="padding: 3px 0; font-size: 13px; line-height: 1.5; color: #171717;">{{ $feedback->viewport }}</td>
            </tr>
        @endif
        @if ($feedback->request_id)
            <tr>
                <td style="padding: 3px 0; font-size: 13px; line-height: 1.5; color: #7d7d7d;">Request ID</td>
                <td style="padding: 3px 0; font-size: 13px; line-height: 1.5; color: #171717; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;">{{ $feedback->request_id }}</td>
            </tr>
        @endif
        @if ($feedback->user_agent)
            <tr>
                <td style="padding: 3px 0; font-size: 12px; line-height: 1.5; color: #8f8f8f;">User agent</td>
                <td style="padding: 3px 0; font-size: 12px; line-height: 1.5; color: #8f8f8f;">{{ $feedback->user_agent }}</td>
            </tr>
        @endif
    </table>
@endsection

@section('footer_note', 'Internal team notification — Partna feedback pipeline.')
