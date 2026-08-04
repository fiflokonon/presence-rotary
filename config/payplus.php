<?php

return [
    'api_key' => env('PAYPLUS_API_KEY', ''),

    'mode' => env('PAYPLUS_MODE', 'test'),

    'token' => env('PAYPLUS_TOKEN', ''),

    'base_url' => env('PAYPLUS_BASE_URL', 'https://app.payplus.africa'),

    'application_name' => env('PAYPLUS_APPLICATION_NAME', ''),

    'application_website_url' => env('PAYPLUS_APPLICATION_WEBSITE_URL', ''),

    'application_cancel_url' => env('PAYPLUS_APPLICATION_CANCEL_URL', ''),

    'application_callback_url' => env('PAYPLUS_APPLICATION_CALLBACK_URL', ''),

    'application_return_url' => env('PAYPLUS_APPLICATION_RETURN_URL', ''),

    'with_redirect' => env('PAYPLUS_WITH_REDIRECT', false),
];
