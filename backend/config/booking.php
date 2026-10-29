<?php

return [
    'slot_interval_minutes' => (int) env('BOOKING_SLOT_INTERVAL_MINUTES', 30),

    'no_show_grace_minutes' => (int) env('BOOKING_NO_SHOW_GRACE_MINUTES', 15),

    'customer_changes' => [
        'minimum_notice_hours' => (int) env('APPOINTMENT_CHANGE_MIN_HOURS', 2),
    ],

    'notifications' => [
        'reminder_lead_minutes' => (int) env('APPOINTMENT_REMINDER_LEAD_MINUTES', 1440),
        'reminder_batch_size' => (int) env('APPOINTMENT_REMINDER_BATCH_SIZE', 100),
    ],

    'guest' => [
        'otp_ttl_minutes' => (int) env('GUEST_BOOKING_OTP_TTL_MINUTES', 5),
        'verification_token_ttl_minutes' => (int) env('GUEST_BOOKING_TOKEN_TTL_MINUTES', 10),
        'otp_max_attempts' => (int) env('GUEST_BOOKING_OTP_MAX_ATTEMPTS', 5),
        'max_active_appointments' => (int) env('GUEST_BOOKING_MAX_ACTIVE_APPOINTMENTS', 3),
        'rate_limits' => [
            'otp_request_ip' => (int) env('GUEST_OTP_REQUESTS_PER_IP', 5),
            'otp_request_email' => (int) env('GUEST_OTP_REQUESTS_PER_EMAIL', 3),
            'otp_verify_ip' => (int) env('GUEST_OTP_VERIFY_PER_IP', 10),
            'booking_ip' => (int) env('GUEST_BOOKING_ATTEMPTS_PER_IP', 10),
            'booking_email' => (int) env('GUEST_BOOKING_ATTEMPTS_PER_EMAIL', 5),
            'lookup_ip' => (int) env('GUEST_LOOKUP_ATTEMPTS_PER_IP', 10),
            'reschedule_ip' => (int) env('GUEST_RESCHEDULE_ATTEMPTS_PER_IP', 5),
            'cancel_ip' => (int) env('GUEST_CANCEL_ATTEMPTS_PER_IP', 5),
        ],
    ],
];
