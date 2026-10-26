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
        // Khóa HMAC mặc định khi chưa có cấu hình trong DB (chỉ dùng lúc tạo bản ghi đầu tiên).
        'webhook_secret' => env('SEPAY_WEBHOOK_SECRET'),
    ],

];
