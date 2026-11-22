<?php

return [
    'rate_limits' => [
        'register_ip_per_minute' => (int) env('AUTH_REGISTER_IP_LIMIT', 5),
        'register_identity_per_minute' => (int) env('AUTH_REGISTER_IDENTITY_LIMIT', 5),
        'login_ip_per_minute' => (int) env('AUTH_LOGIN_IP_LIMIT', 30),
        'login_identity_per_minute' => (int) env('AUTH_LOGIN_IDENTITY_LIMIT', 10),
        'google_ip_per_minute' => (int) env('AUTH_GOOGLE_IP_LIMIT', 10),
        'guest_ip_per_minute' => (int) env('AUTH_GUEST_IP_LIMIT', 10),
        'device_ip_per_minute' => (int) env('AUTH_DEVICE_IP_LIMIT', 30),
        'password_reset_ip_per_minute' => (int) env('AUTH_PASSWORD_RESET_IP_LIMIT', 5),
        'password_reset_identity_per_minute' => (int) env('AUTH_PASSWORD_RESET_IDENTITY_LIMIT', 3),
        'username_check_ip_per_minute' => (int) env('AUTH_USERNAME_CHECK_IP_LIMIT', 30),
    ],
];
