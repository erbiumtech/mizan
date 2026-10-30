<!doctype html>
<html>
<body style="margin:0;padding:24px;background:#f6f6f6;font-family:{{ $brand['font'] }};color:#222;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#ffffff;border-top:4px solid {{ $brand['colour'] }};">
        <tr>
            <td style="padding:28px 32px;font-size:15px;line-height:1.6;">
                @foreach ($paragraphs as $paragraph)
                    <p style="margin:0 0 14px;">{!! nl2br(e($paragraph)) !!}</p>
                @endforeach
            </td>
        </tr>
        <tr>
            <td style="padding:16px 32px 24px;font-size:12px;line-height:1.5;color:#777;border-top:1px solid #eee;">
                {{ $company }} is writing to you because you agreed to hear from us by email.
                To stop, reply to this message and say so.
            </td>
        </tr>
    </table>
</body>
</html>
