<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Transitional Authentication Controls
    |--------------------------------------------------------------------------
    |
    | These flags govern the availability of legacy credential authentication
    | paths during the transitional dual-authentication phase. In Phase 8,
    | defaults fail closed (false) to enforce strict authentication authority.
    |
    */

    'legacy_login_enabled' => env('AUTH_LEGACY_LOGIN_ENABLED', false),

    'legacy_registration_enabled' => env('AUTH_LEGACY_REGISTRATION_ENABLED', false),
];
