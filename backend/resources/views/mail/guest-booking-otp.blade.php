<x-mail::message>
# {{ config('app.name') }} booking verification

Your verification code is:

<x-mail::panel>
{{ $otp }}
</x-mail::panel>

This code expires in {{ $ttlMinutes }} minutes.

If you did not request this code, you can safely ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
