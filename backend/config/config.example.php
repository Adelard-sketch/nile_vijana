<?php
/**
 * Copy this file to config.php and fill in your own values.
 * config.php must never be committed to Git (it is in .gitignore).
 */
return [

    'app' => [
        'name'     => 'Nile Vijana Creatives API',
        'env'      => env('APP_ENV', 'development'),   // development | production
        'debug'    => env('APP_DEBUG', true),                   // show error details in JSON (turn off in production)
        // Public URL of backend/public, without a trailing slash.
        // XAMPP example: http://localhost/nilevijanacreatives/backend/public
        'base_url' => env('APP_URL', 'http://localhost/nilevijanacreatives/backend/public'),
        // Public URL of the website (the frontend folder). Used in emails and as the
        // page Paystack returns to after a payment. Leave empty to derive it from base_url.
        'frontend_url' => env('FRONTEND_URL', ''),
        // Long random secret used to sign download links. Generate one with:
        //   php -r "echo bin2hex(random_bytes(32));"
        'key'      => env('APP_KEY', 'CHANGE_ME_TO_A_LONG_RANDOM_STRING'),
        'timezone' => 'Africa/Accra',
        'country'      => 'GH',
        'calling_code' => '233',              // local numbers like 024 123 4567 become 233241234567
        // Frontend origins allowed to call the API from a browser (CORS). '*' allows all.
        'cors_origins' => ['http://localhost:3000', 'http://localhost:5173', 'http://localhost:5500', 'http://127.0.0.1:5500', 'http://localhost'],
        'token_days'   => 30,                 // how long a login token stays valid
    ],

    'db' => [
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => (int) env('DB_PORT', 3306),
        'name' => env('DB_NAME', 'nile_vijana'),
        'user' => env('DB_USER', 'root'),
        'pass' => env('DB_PASS', ''),         // XAMPP default root password is empty
    ],

    'money' => [
        // Amounts are stored as whole Ghana cedis (GH₵). Paystack is sent pesewas (x100).
        'currency'               => 'GHS',
        'symbol'                 => 'GH₵',
        'commission_sales'       => 0.08,     // beats, songs and tips
        'commission_bookings'    => 0.10,     // studio bookings
        'deposit_rate'           => 0.30,     // deposit to confirm a studio booking
        'min_payout'             => 50,       // GH₵
        'min_tip'                => 5,        // GH₵
        'max_tip'                => 5000,     // GH₵
        'max_track_price'        => 500,      // GH₵, download price of a song
        'min_license_price'      => 10,       // GH₵, cheapest beat licence
        'max_license_price'      => 50000,    // GH₵
        'min_room_rate'          => 20,       // GH₵ per hour
        'max_room_rate'          => 10000,    // GH₵ per hour
        'free_cancel_hours'      => 24,       // artist can cancel for a full refund until this many hours before
        'booking_hold_minutes'   => 15,       // a slot is held this long while the artist pays
        'booking_response_hours' => 48,       // a studio must accept or reject a paid request within this time, or it is refunded
        'order_expiry_minutes'   => 30,       // unpaid orders expire after this
        'play_threshold_seconds' => 30,       // a listen counts as a "play" after this many seconds
    ],

    'payments' => [
        // 'paystack' = Paystack (Ghana: cards and MTN / Telecel / AirtelTigo mobile money, in GHS).
        //              Put your keys in backend/.env (PAYSTACK_SECRET_KEY, PAYSTACK_PUBLIC_KEY).
        //              Test keys (sk_test_...) take test payments only; live keys take real money.
        //              Without a secret key every payment is refused with a clear message:
        //              nothing is ever marked paid without Paystack confirming it.
        // 'sandbox'  = local automated tests only (refused when app.env is 'production').
        'gateway' => env('PAYMENT_GATEWAY', 'paystack'),
        'paystack' => [
            'secret_key'  => env('PAYSTACK_SECRET_KEY', ''),
            'public_key'  => env('PAYSTACK_PUBLIC_KEY', ''),
            'base_url'    => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
            // Channels shown on the Paystack checkout.
            'channels'    => ['card', 'mobile_money', 'bank_transfer'],
            // Send creator withdrawals with Paystack Transfers (needs Transfers enabled on your
            // Paystack business). When false, admins send the mobile money themselves and
            // record it with "Mark as sent".
            'transfers'   => env('PAYSTACK_TRANSFERS', false),
        ],
        'sandbox' => [
            'complete_after_seconds' => 5,
        ],
    ],

    'spotify' => [
        // Create a Spotify app and keep these credentials on the backend only.
        // Optional: used for the Spotify search on the Music page. Featured Spotify
        // songs and playlists play through Spotify's embed player without credentials.
        'client_id'     => env('SPOTIFY_CLIENT_ID', ''),
        'client_secret' => env('SPOTIFY_CLIENT_SECRET', ''),
    ],

    'mail' => [
        // 'log'  = write every email to storage/logs/mail/ (development; sign-in codes are also
        //          shown on screen while app.env is 'development').
        // 'smtp' = send through an SMTP server (Gmail, Zoho, Mailgun, Brevo, cPanel mail...).
        'driver'     => env('MAIL_DRIVER', 'log'),
        'from'       => env('MAIL_FROM', 'no-reply@nilevijanacreatives.com'),
        'from_name'  => env('MAIL_FROM_NAME', 'Nile Vijana Creatives'),
        'reply_to'   => env('MAIL_REPLY_TO', ''),
        'smtp' => [
            'host'       => env('SMTP_HOST', ''),
            'port'       => (int) env('SMTP_PORT', 587),
            'username'   => env('SMTP_USERNAME', ''),
            'password'   => env('SMTP_PASSWORD', ''),
            'encryption' => env('SMTP_ENCRYPTION', 'tls'),   // tls (port 587) | ssl (port 465) | none
            'timeout'    => 15,
        ],
    ],

    'auth' => [
        'otp_minutes'         => 10,          // a sign-in code works this long
        'otp_max_attempts'    => 5,           // wrong codes allowed per code
        'otp_resend_seconds'  => 60,          // wait between "send a new code"
        'otp_max_sends'       => 5,           // codes per sign-in attempt
        'login_max_failures'  => 5,           // wrong passwords per account in 15 minutes
        'login_ip_failures'   => 20,          // wrong passwords per IP address in 15 minutes
    ],

    'sms' => [
        // Booking and payout alerts. 'log' writes them to storage/logs/sms.log;
        // 'africastalking' sends real SMS (Africa's Talking covers Ghana).
        'driver' => 'log',
        'africastalking' => [
            'username' => env('AT_USERNAME', 'sandbox'),
            'api_key'  => env('AT_API_KEY', ''),
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
        // No longer used: songs, beats and studios from creators always wait for an admin
        // (admins can publish their own uploads straight away). Kept so old configs still load.
        'auto_approve' => false,
    ],
];
