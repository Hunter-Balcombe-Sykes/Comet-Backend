<p>Hi,</p>

<p>
    Your old handle <strong>{{ $alias->handle }}</strong> will be released from the redirect pool
    {{ $bucket === 't3' ? 'in 3 days' : 'tomorrow' }}
    (on {{ \Carbon\Carbon::parse($alias->expires_at)->toFormattedDateString() }}).
</p>

<p>
    After release, anyone may claim this handle. If you'd like it back,
    @if($alias->reclaim_until && \Carbon\Carbon::parse($alias->reclaim_until)->isFuture())
    you can reclaim it for free from your dashboard before the deadline.
    @else
    you'll need to rename to it normally (subject to the 30-day cooldown).
    @endif
</p>

<p>The Partna team</p>
