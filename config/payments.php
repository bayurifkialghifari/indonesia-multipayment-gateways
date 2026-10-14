<?php

declare(strict_types=1);

return [
    'default' => env('PAYMENTS_GATEWAY', 'midtrans'),
    'connect_timeout' => 10,
    'timeout' => 30,
    'gateways' => [
        'midtrans' => [
            'environment' => env('MIDTRANS_ENVIRONMENT', 'sandbox'),
            'server_key' => env('MIDTRANS_SERVER_KEY'),
        ],
        'xendit' => [
            'secret_key' => env('XENDIT_SECRET_KEY'),
            'webhook_token' => env('XENDIT_WEBHOOK_TOKEN'),
        ],
        'doku' => [
            'environment' => env('DOKU_ENVIRONMENT', 'sandbox'),
            'client_id' => env('DOKU_CLIENT_ID'),
            'secret_key' => env('DOKU_SECRET_KEY'),
            'private_key' => env('DOKU_PRIVATE_KEY'),
            'public_key' => env('DOKU_PUBLIC_KEY'),
            'private_key_passphrase' => env('DOKU_PRIVATE_KEY_PASSPHRASE', ''),
            'merchant_id' => env('DOKU_MERCHANT_ID'),
            'terminal_id' => env('DOKU_TERMINAL_ID'),
            'postal_code' => env('DOKU_POSTAL_CODE'),
            'channel_id' => 'H2H',
            'snap_webhook_signature' => env('DOKU_SNAP_WEBHOOK_SIGNATURE', 'symmetric'),
            'banks' => [],
        ],
        'paywuz' => [
            'api_key' => env('PAYWUZ_API_KEY'),
        ],
        'ipaymu' => [
            'environment' => env('IPAYMU_ENVIRONMENT', 'sandbox'),
            'va' => env('IPAYMU_VA'),
            'api_key' => env('IPAYMU_API_KEY'),
        ],
    ],
];
