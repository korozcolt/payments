<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Payment Driver
    |--------------------------------------------------------------------------
    |
    | This option controls the default payment driver that will be used when
    | calling Payments::charge() without specifying a driver explicitly.
    |
    */
    'default' => env('PAYMENTS_DEFAULT', 'wompi'),

    /*
    |--------------------------------------------------------------------------
    | Enabled Drivers
    |--------------------------------------------------------------------------
    |
    | This option controls which payment drivers are enabled in your application.
    | If a driver is not in this list, attempting to use it will throw an exception.
    | Set to empty array or null to enable all drivers.
    |
    */
    'enabled' => env('PAYMENTS_ENABLED') ? explode(',', env('PAYMENTS_ENABLED')) : ['wompi', 'mercadopago', 'epayco'],

    /*
    |--------------------------------------------------------------------------
    | Use Database Configuration
    |--------------------------------------------------------------------------
    |
    | When true, driver credentials are loaded from the payment_gateways table.
    | When false, credentials are loaded from this config file (env-based).
    |
    */
    'use_database' => env('PAYMENTS_USE_DATABASE', true),

    /*
    |--------------------------------------------------------------------------
    | Webhook Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the webhook endpoints that receive payment notifications
    | from the payment providers.
    |
    */
    'webhooks' => [
        'prefix' => env('PAYMENTS_WEBHOOK_PREFIX', 'payments/webhooks'),
        'middleware' => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes Configuration
    |--------------------------------------------------------------------------
    */
    'routes' => [
        'enabled' => true,
        'prefix' => env('PAYMENTS_ROUTES_PREFIX', 'payments'),
        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | URLs
    |--------------------------------------------------------------------------
    |
    | Default URLs for redirects and webhooks. These can be overridden
    | per-transaction when creating a payment.
    |
    */
    'urls' => [
        'return' => env('PAYMENTS_RETURN_URL'),
        'webhook' => env('PAYMENTS_WEBHOOK_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Driver Configurations
    |--------------------------------------------------------------------------
    |
    | Configuration for each payment driver. These settings are used when
    | use_database is set to false, or as fallback values.
    |
    */
    'drivers' => [
        'wompi' => [
            'sandbox' => env('WOMPI_SANDBOX', true),
            'public_key' => env('WOMPI_PUBLIC_KEY'),
            'private_key' => env('WOMPI_PRIVATE_KEY'),
            'integrity_secret' => env('WOMPI_INTEGRITY_KEY'),
            'events_secret' => env('WOMPI_EVENTS_SECRET'),
            'base_url' => [
                'sandbox' => 'https://sandbox.wompi.co/v1',
                'production' => 'https://production.wompi.co/v1',
            ],
            'widget_url' => 'https://checkout.wompi.co/widget.js',
        ],

        'mercadopago' => [
            'sandbox' => env('MERCADOPAGO_SANDBOX', true),
            'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),
            'public_key' => env('MERCADOPAGO_PUBLIC_KEY'),
            'webhook_secret' => env('MERCADOPAGO_WEBHOOK_SECRET'),
            'base_url' => 'https://api.mercadopago.com',
            'widget_url' => 'https://sdk.mercadopago.com/js/v2',
        ],

        'epayco' => [
            'sandbox' => env('EPAYCO_SANDBOX', true),
            'public_key' => env('EPAYCO_PUBLIC_KEY'),
            'private_key' => env('EPAYCO_PRIVATE_KEY'),
            'p_cust_id_cliente' => env('EPAYCO_P_CUST_ID'),
            'p_key' => env('EPAYCO_P_KEY'),
            'base_url' => 'https://secure.epayco.co',
            'widget_url' => 'https://checkout.epayco.co/checkout.js',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    */
    'currency' => env('PAYMENTS_CURRENCY', 'COP'),

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Configure logging for payment operations.
    |
    */
    'logging' => [
        'enabled' => env('PAYMENTS_LOGGING_ENABLED', true),
        'channel' => env('PAYMENTS_LOG_CHANNEL', 'stack'),
    ],
];
