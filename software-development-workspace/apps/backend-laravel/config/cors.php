<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | This HRMS API is consumed by two clients:
    |
    |   1. Vue SPA (web)    — browser-based; needs CORS + supports_credentials
    |      Uses Sanctum session cookies (stateful). Origin must be listed here.
    |
    |   2. React Native app — native; NOT a browser. No CORS enforcement.
    |      Uses Sanctum Bearer tokens (stateless). No CORS entry needed.
    |
    | Set CORS_ALLOWED_ORIGINS in .env to your Vue origin(s).
    |
    */

    'paths' => ['api/*', 'api/public/*', 'sanctum/csrf-cookie', 'login', 'logout'],

    'allowed_methods' => explode(',', env('CORS_ALLOWED_METHODS', 'GET,POST,PUT,PATCH,DELETE,OPTIONS')),

    'allowed_origins' => explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173')),

    'allowed_origins_patterns' => [],

    'allowed_headers' => explode(',', env('CORS_ALLOWED_HEADERS', 'Content-Type,Authorization,X-Requested-With,X-HRMS-Version')),

    // Expose Authorization so mobile web-views and browser clients can read the token
    'exposed_headers' => explode(',', env('CORS_EXPOSED_HEADERS', 'Authorization')),

    'max_age' => (int) env('CORS_MAX_AGE', 86400),

    // Must be true for Sanctum SPA cookie auth to work from the Vue origin
    'supports_credentials' => true,

];

