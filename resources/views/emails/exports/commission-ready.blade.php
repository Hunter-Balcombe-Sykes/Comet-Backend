{{-- resources/views/emails/exports/commission-ready.blade.php --}}
<!doctype html>
<html><body style="font-family:-apple-system,BlinkMacSystemFont,sans-serif;line-height:1.5;color:#222;max-width:560px;margin:auto;padding:24px;">

<p>Hi,</p>

<p>Your commission transactions export is ready.</p>

<p style="margin:24px 0;">
    <strong>{{ $recordCount }}</strong> transactions
    @if($dateFrom && $dateTo)
        from <strong>{{ $dateFrom }}</strong> to <strong>{{ $dateTo }}</strong>
    @endif
    — {{ strtoupper($format) }} format.
</p>

<p>
    <a href="{{ $signedUrl }}"
       style="display:inline-block;background:#111;color:#fff;padding:12px 24px;text-decoration:none;border-radius:6px;">
        Download your report
    </a>
</p>

<p style="color:#666;font-size:14px;margin-top:24px;">
    This link expires in <strong>{{ $ttlDays }} days</strong> (at {{ $expiresAt->format('j M Y') }}).
    If it expires, request a new export from your dashboard.
</p>

<p style="color:#666;font-size:13px;margin-top:24px;">
    If you didn't request this export, please contact support.
</p>

</body></html>
