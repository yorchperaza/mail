<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title ?? ($brand ?? 'MonkeysMail') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
</head>
<body style="margin:0;padding:24px;background:#f7f8fa;
             font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#111">
<table role="presentation" cellpadding="0" cellspacing="0" width="100%"
       style="max-width:640px;margin:0 auto;background:#fff;border-radius:12px;
                box-shadow:0 1px 4px rgba(0,0,0,.06)">
    <tr>
        <td style="padding:20px 24px 8px;font-size:18px;font-weight:700">
            {{ $brand ?? 'MonkeysMail' }}
        </td>
    </tr>
    <tr>
        <td style="padding:0 24px 4px;font-size:14px;color:#6b7280">
            @yield('title')
        </td>
    </tr>
    <tr>
        <td style="padding:8px 24px 24px;line-height:1.6">
            @yield('content')
        </td>
    </tr>
    <tr>
        <td style="padding:12px 24px 24px;font-size:12px;color:#6b7280">
            This is a service message from {{ $brand ?? 'MonkeysMail' }}.
        </td>
    </tr>
</table>
</body>
</html>
