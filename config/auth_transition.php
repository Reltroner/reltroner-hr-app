<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Transitional Authentication Controls
    |--------------------------------------------------------------------------
    |
    | These flags govern the availability of legacy credential authentication
    | paths during the transitional dual-authentication phase. In Phase 8B,
    | compatibility defaults are enabled (true) to prevent lockout.
    |
    */

    'legacy_login_enabled' => env('AUTH_LEGACY_LOGIN_ENABLED', true),

    'legacy_registration_enabled' => env('AUTH_LEGACY_REGISTRATION_ENABLED', true),

];
