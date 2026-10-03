<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
</head>
<body style="margin: 0; padding: 24px 12px; background-color: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 560px; margin: 0 auto;">
        <tr>
            <td>
                <div style="background-color: #ffffff; border-radius: 16px; border: 1px solid #e2e8f0; padding: 26px 24px;">
                    <div style="font-size: 13px; font-weight: 700; color: #c2410c; letter-spacing: 0.5px; margin-bottom: 8px;">MENGLISH</div>
                    <h1 style="margin: 0 0 16px 0; font-size: 19px; font-weight: 800; color: #0f172a; line-height: 1.4;">{{ $title }}</h1>
                    <div style="font-size: 15px; color: #334155; line-height: 1.65; white-space: pre-wrap; overflow-wrap: anywhere; word-break: break-word;">{{ $body }}</div>
                    @if (! empty($actionUrl))
                        <div style="margin-top: 22px; text-align: center;">
                            <a href="{{ $actionUrl }}" style="display: inline-block; background-color: #c2410c; color: #ffffff; padding: 11px 22px; border-radius: 10px; text-decoration: none; font-weight: 700; font-size: 14px;">{{ $actionText ?: 'Mở' }}</a>
                        </div>
                    @endif
                </div>
                <p style="margin: 14px 4px 0; font-size: 12px; color: #94a3b8; line-height: 1.5; text-align: center;">
                    Email gửi tự động từ hệ thống MEnglish, vui lòng không trả lời email này. Cần hỗ trợ, phụ huynh vui lòng liên hệ trung tâm.
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
