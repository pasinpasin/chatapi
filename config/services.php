<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'groq' => [
        'key'   => env('GROQ_API_KEY'),
       'model' => env('GROQ_MODEL', 'llama-3.3-70b-versatile'),
    ],

    'whatsapp' => [
        'token'             => env('WHATSAPP_TOKEN'),
        'phone_number_id'   => env('WHATSAPP_PHONE_NUMBER_ID'),
        'verify_token'      => env('WHATSAPP_VERIFY_TOKEN'),
    ], // Kjo kllapë mbyll tani të gjithë skedarin saktë

];