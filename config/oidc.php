<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Deployment-Specific OIDC Settings (Fail-Closed)
    |--------------------------------------------------------------------------
    |
    | These critical deployment settings have NO silent fallback defaults.
    | They must be explicitly provided by the deployment environment.
    |
    */

    'issuer' => env('OIDC_ISSUER'),
    'client_id' => env('OIDC_CLIENT_ID'),
    'client_secret' => env('OIDC_CLIENT_SECRET'),
    'redirect_uri' => env('OIDC_REDIRECT_URI'),
    'post_logout_redirect_uri' => env('OIDC_POST_LOGOUT_REDIRECT_URI'),
    'environment' => env('OIDC_ENVIRONMENT'),
    'expected_identity_class' => env('OIDC_EXPECTED_IDENTITY_CLASS'),

    /*
    |--------------------------------------------------------------------------
    | Safe Protocol Defaults
    |--------------------------------------------------------------------------
    |
    | Standard baseline settings permitted with safe defaults.
    |
    */

    'scopes' => env('OIDC_SCOPES', 'openid profile email'),
    'transaction_ttl' => (int) env('OIDC_TRANSACTION_TTL', 300),
    'pkce_method' => 'S256',
    'clock_skew' => (int) env('OIDC_CLOCK_SKEW', 60),
    'jwks_cache_ttl' => (int) env('OIDC_JWKS_CACHE_TTL', 3600),
    'http_timeout' => (int) env('OIDC_HTTP_TIMEOUT', 5),

];
