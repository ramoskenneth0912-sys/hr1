<?php

return [

    'paths' => ['api/*', 'up'],

    'allowed_methods' => ['*'],

    // Mirrors the allowlist approach of api/v1/bootstrap.php.
    'allowed_origins' => [
        'http://localhost',
        'https://localhost',
        'http://127.0.0.1',
        'https://127.0.0.1',
        'http://localhost:5173',
        'http://127.0.0.1:5173',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => true,

];
