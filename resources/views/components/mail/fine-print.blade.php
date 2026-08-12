{{--
  Quiet fine-print band that sits under a CTA: a hairline rule detaches it from
  the message, the copy drops to 12px muted, and the plain-URL fallback (for
  clients that don't render the button) folds into one short line instead of a
  standalone "Button not working?" paragraph.

  Props:
    url — optional. The CTA's destination; when present, renders the
          "Or paste this link" fallback line.

  Slot: the fine print itself (expiry, single-use, safe-to-ignore). Keep it to
  one or two short sentences — this band is deliberately the quietest thing in
  the email body.

  Usage:
    <x-mail.fine-print :url="$verifyUrl">
        This link expires in 1 hour. Didn't request it? Just ignore this email.
    </x-mail.fine-print>
--}}
@props([
    'url' => null,
])

{{-- No rule of its own: the size/colour drop plus the gap does the separating —
     the email's one structural hairline belongs to the footer. --}}
<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 32px 0 0 0;">
    <tr>
        <td>
            <p class="text-secondary" style="margin: 0; font-size: 12px; line-height: 1.6; color: #8f8f8f;">{{ trim($slot) }}</p>
            @if ($url)
                <p class="text-secondary" style="margin: 6px 0 0 0; font-size: 12px; line-height: 1.6; color: #8f8f8f; word-break: break-all;">
                    Or paste this link: <a href="{{ $url }}" style="color: #1367fb; text-decoration: none;">{{ $url }}</a>
                </p>
            @endif
        </td>
    </tr>
</table>
