<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'ScholarZim' }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f6fb;font-family:Segoe UI,Helvetica,Arial,sans-serif;color:#1f2430;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6fb;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                       style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;">

                    <tr>
                        <td style="background:#0066fe;padding:20px 24px;color:#ffffff;font-size:18px;font-weight:700;">
                            ScholarZim
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:24px;font-size:15px;line-height:1.6;">
                            @yield('body')

                            @isset($actionUrl)
                                @if($actionUrl)
                                    <p style="margin:24px 0;">
                                        <a href="{{ $actionUrl }}"
                                           style="display:inline-block;background:#0066fe;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:600;">
                                            {{ $actionLabel ?? 'Open ScholarZim' }}
                                        </a>
                                    </p>
                                    <p style="margin:0;font-size:13px;color:#6b7280;">
                                        If the button does not work, copy this link into your browser:<br>
                                        <span style="word-break:break-all;">{{ $actionUrl }}</span>
                                    </p>
                                @endif
                            @endisset
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:16px 24px;background:#f8fafc;font-size:12px;color:#6b7280;">
                            You are receiving this because you have a ScholarZim account.
                            Manage which emails you get from Security &amp; privacy in your dashboard.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
