<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'sepay' => [
        // Cờ bật/tật webhook SePay. Tắt tạm thời: endpoint trả 503 sạch thay vì xử lý.
        'webhook_enabled' => env('SEPAY_WEBHOOK_ENABLED', false),
    ],

    'zalo' => [
        'app_id' => env('ZALO_APP_ID'),
        'secret_key' => env('ZALO_SECRET_KEY'),
        'oa_id' => env('ZALO_OA_ID'),
        'access_token' => env('ZALO_ACCESS_TOKEN'),
        'template_id_bigtest' => env('ZALO_ZNS_TEMPLATE_BIGTEST', '342918'),
        'template_id_debt' => env('ZALO_ZNS_TEMPLATE_DEBT', '342919'),
        'mode' => env('ZALO_ZNS_MODE', 'sandbox'), // sandbox | live
    ],

];
