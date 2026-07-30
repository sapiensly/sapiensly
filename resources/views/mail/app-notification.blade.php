{{-- The notification email a workflow's notify.send step produces.

     Table-based and inline-styled on purpose: this is the one surface in the
     product that renders in Outlook and Gmail, where flexbox, custom properties
     and external stylesheets are unreliable or stripped. Nothing here is shared
     with the app's CSS, and that is deliberate.

     Every value is escaped — the body carries record data, which is user input. --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
       style="margin:0;padding:0;background:#f5f6f8;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <tr>
        <td align="center" style="padding:32px 16px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;">

                {{-- A thin accent rule instead of a coloured header block: it
                     carries the brand without betting on an image loading. --}}
                <tr>
                    <td style="height:4px;background:{{ $accent }};font-size:0;line-height:0;">&nbsp;</td>
                </tr>

                @if ($logo)
                    <tr>
                        <td style="padding:24px 32px 0;">
                            <img src="{{ $logo }}" alt="{{ $senderName }}" height="28"
                                 style="height:28px;width:auto;display:block;border:0;">
                        </td>
                    </tr>
                @endif

                <tr>
                    <td style="padding:24px 32px 8px;">
                        <h1 style="margin:0;font-size:20px;line-height:1.35;font-weight:600;color:#111827;">
                            {{ $title }}
                        </h1>
                    </td>
                </tr>

                @foreach ($paragraphs as $paragraph)
                    <tr>
                        <td style="padding:0 32px 12px;">
                            <p style="margin:0;font-size:15px;line-height:1.6;color:#374151;white-space:pre-line;">{{ $paragraph }}</p>
                        </td>
                    </tr>
                @endforeach

                @if ($link)
                    <tr>
                        <td style="padding:12px 32px 28px;">
                            <a href="{{ $link }}"
                               style="display:inline-block;padding:10px 20px;border-radius:8px;background:{{ $accent }};color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;">
                                Ver detalle
                            </a>
                        </td>
                    </tr>
                @else
                    <tr><td style="padding:0 32px 16px;">&nbsp;</td></tr>
                @endif

                <tr>
                    <td style="padding:16px 32px 24px;border-top:1px solid #f1f2f4;">
                        <p style="margin:0;font-size:12px;line-height:1.5;color:#9ca3af;">
                            {{ $senderName }}
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
