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
    'raven23_auth' => [
        // Keep disabled until a mail provider and application/deep-link URL are configured.
        'mail_enabled' => (bool) env('RAVEN23_AUTH_MAIL_ENABLED', false),
        'action_url' => env('RAVEN23_AUTH_ACTION_URL', ''),
    ],
    'raven23_push' => [
        // A provider must be selected and its adapter explicitly approved before sending.
        'enabled' => (bool) env('RAVEN23_PUSH_ENABLED', false),
        'provider' => env('RAVEN23_PUSH_PROVIDER', 'none'),
    ],
    'google' => [
        // Same public Web OAuth client ID used by the Flutter GoogleSignIn client.
        // An empty/missing Render value falls back to this matching client ID.
        'client_id' => env('GOOGLE_CLIENT_ID') ?: '1021333219041-pvcdfbbia4qm8auut13usj6sf329ebb3.apps.googleusercontent.com',
    ],
];
