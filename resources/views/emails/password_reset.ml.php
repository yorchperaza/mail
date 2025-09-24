@extends('layouts.email')

@section('title')
Reset your password
@endsection

@section('content')
<p style="margin:0 0 16px">Hi {{ $name ?? 'there' }},</p>

<p style="margin:0 0 16px">
    We received a request to reset your {{ $brand ?? 'MonkeysMail' }} password.
</p>

<p style="margin:0 0 20px">
    <a href="{{ $resetUrl }}"
       style="display:inline-block;padding:12px 18px;border-radius:8px;
            background:#2563eb;color:#fff;text-decoration:none;font-weight:600">
        Reset password
    </a>
</p>

<p style="margin:0 0 16px;color:#374151">
    This link expires in {{ $ttlMin ?? 60 }} minutes. If you didn’t request this,
    you can safely ignore this email.
</p>

<p style="margin:24px 0 0;color:#6b7280">— {{ $brand ?? 'MonkeysMail' }} Security</p>

<hr style="border:none;border-top:1px solid #e5e7eb;margin:24px 0">

<p style="margin:0;color:#6b7280;font-size:12px;line-height:1.5">
    Trouble with the button? Copy and paste this URL into your browser:<br>
    <span style="word-break:break-all">{{ $resetUrl }}</span>
</p>
@endsection
