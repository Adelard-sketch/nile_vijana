<?php
/**
 * Copy this file to config.php and fill in your own values.
 * config.php must never be committed to Git (it is in .gitignore).
 */
return [

    'app' => [
        'name'     => 'Nile Vijana Creatives API',
        'env'      => 'development',          // development | production
        'debug'    => true,                   // show error details in JSON (turn off in production)
        // Public URL of the project folder, without a trailing slash.
        // XAMPP example: http://localhost/nile-vijana-api
        'base_url' => 'http://localhost/nile-vijana-api',
        // Long random secret used to sign download links. Generate one with:
        //   php -r "echo bin2hex(random_bytes(32));"
        'key'      => 'CHANGE_ME_TO_A_LONG_RANDOM_STRING',
        'timezone' => 'Africa/Kigali',
        // Frontend origins allowed to call the API from a browser (CORS). '*' allows all.
        'cors_origins' => ['http://localhost:3000', 'http://localhost:5173', 'http://localhost'],
        'token_days'   => 30,                 // how long a login token stays valid
    ],

    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'nile_vijana',
        'user' => 'root',
        'pass' => '',                         // XAMPP default root password is empty
    ],

    'money' => [
        'currency'               => 'RWF',
        'commission_sales'       => 0.15,     // beats, songs and tips
        'commission_bookings'    => 0.10,     // studio bookings
        'deposit_rate'           => 0.30,     // deposit to confirm a studio booking
        'min_payout'             => 1000,     // RWF
        'min_tip'                => 100,      // RWF
        'free_cancel_hours'      => 24,       // artist can cancel for a full refund until this many hours before
        'booking_hold_minutes'   => 10,       // a slot is held this long while the artist pays
        'order_expiry_minutes'   => 30,       // unpaid orders expire after this
        'play_threshold_seconds' => 30,       // a listen counts as a "play" after this many seconds
    ],

    'payments' => [
        // 'sandbox' = built-in fake mobile money for demos (no account needed).
        //             Payments complete after a few seconds; numbers ending in 99 fail.
        // 'pawapay' = real pawaPay API (use their sandbox URL while testing).
        'gateway' => 'sandbox',
        'sandbox' => [
            'complete_after_seconds' => 5,
        ],
        'pawapay' => [
            'base_url' => 'https://api.sandbox.pawapay.io',   // production: https://api.pawapay.io
            'token'    => '',                                 // API token from the pawaPay dashboard
        ],
    ],

    'sms' => [
        // 'log' writes messages to storage/logs/sms.log (and returns sign-in codes in
        //  development so you can test without a phone). 'africastalking' sends real SMS.
        'driver' => 'log',
        'africastalking' => [
            'username' => 'sandbox',
            'api_key'  => '',
            'from'     => '',                 // optional sender ID
            'sandbox'  => true,
        ],
    ],

    'uploads' => [
        // PHP's own limits must allow these too: upload_max_filesize and post_max_size in php.ini.
        'max_audio_mb' => 50,
        'max_image_mb' => 5,
        'max_stems_mb' => 200,
    ],

    'media' => [
        // FFmpeg makes low-bitrate stream files and short previews. Optional:
        // with 'enabled' => false, producers must upload their own preview file.
        'ffmpeg_enabled'  => false,
        'ffmpeg'          => 'ffmpeg',        // full path on Windows, e.g. C:\\ffmpeg\\bin\\ffmpeg.exe
        'ffprobe'         => 'ffprobe',
        'preview_seconds' => 60,
        'stream_bitrate'  => '96k',
        // Optional voice tag mixed into beat previews (e.g. storage/tag.mp3 saying "Nile Vijana")
        'tag_file'        => null,
    ],

    'moderation' => [
        // true = new uploads and studios go live without admin review (handy for demos)
        'auto_approve' => false,
    ],
];
