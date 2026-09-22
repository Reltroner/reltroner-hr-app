<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Oidc\OidcAuthorizationService;
use Illuminate\Http\RedirectResponse;

class OidcRedirectController extends Controller
{
    /**
     * Initiate the Keycloak OIDC authorization flow.
     */
    public function redirect(OidcAuthorizationService $service): RedirectResponse
    {
        $authorizationUrl = $service->buildAuthorizationUrl();

        return redirect()->away($authorizationUrl);
    }
}
