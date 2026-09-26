<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Oidc\Exceptions\OidcCallbackException;
use App\Modules\Identity\Oidc\OidcCallbackService;
use App\Modules\Identity\Oidc\OidcSessionManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class OidcCallbackController extends Controller
{
    /**
     * Handle the OIDC authorization callback.
     */
    public function callback(
        Request $request,
        OidcCallbackService $service,
        OidcSessionManager $sessionManager
    ): Response|RedirectResponse {
        try {
            $result = $service->handleCallback($request);

            $sessionManager->establish(
                $request,
                $result->resolvedIdentity,
                $result->idTokenHint()
            );

            return redirect()->intended(
                route('dashboard', absolute: false)
            );
        } catch (OidcCallbackException $e) {
            return response($e->getUserFacingMessage(), $e->getStatusCode());
        }
    }
}
