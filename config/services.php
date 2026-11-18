<?php

return [
    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],
    'resend' => [
        'key' => env('RESEND_KEY'),
    ],
    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],
    'google' => [
        // Same public Web OAuth client ID used by the Flutter GoogleSignIn client.
        // Render can override this value without requiring another APK build.
        'client_id' => env(
            'GOOGLE_CLIENT_ID',
            '1021333219041-pvcdfbbia4qm8auut13usj6sf329ebb3.apps.googleusercontent.com'
        ),
    ],
];
