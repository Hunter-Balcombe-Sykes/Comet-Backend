{{-- Shared content block for every category-driven notification email.
     Renders the Notification model's title / body / cta_url / primary_action_label
     inside the universal Partna layout. Per-category templates exist so we
     can customize copy later, but until then they all just @include this. --}}
<h1 class="headline text-primary" style="margin: 0 0 16px 0; font-family:-apple-system,BlinkMacSystemFont,'SF Pro Display','Segoe UI',Roboto,sans-serif; font-size: 32px; font-weight: 600; line-height: 1.125; letter-spacing: -0.022em; color: #171717;">
    {{ $notification->title }}
</h1>

{{-- Interpolation stays inline with the tags: white-space:pre-wrap would
     otherwise render the template's own newline + indentation as a first-line
     indent. --}}
<p class="body-text text-primary" style="margin: 0 0 24px 0; font-size: 17px; line-height: 1.47; color: #171717; white-space: pre-wrap;">{{ $notification->body }}</p>

@if ($notification->cta_url)
    <x-mail.button :href="$notification->cta_url">
        {{ $notification->primary_action_label ?? 'View' }}
    </x-mail.button>
@endif
