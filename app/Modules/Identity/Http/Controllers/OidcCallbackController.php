<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Oidc\Exceptions\OidcCallbackException;
use App\Modules\Identity\Oidc\OidcCallbackService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class OidcCallbackController extends Controller
{
    /**
     * Handle the OIDC authorization callback.
     */
    public function callback(Request $request, OidcCallbackService $service): Response
    {
        try {
            $service->handleCallback($request);

            // Intermediate boundary for Phase 7F:
            // Approved local identity is resolved, but Laravel session
            // establishment belongs to Phase 7G.
            return response('SSO login is not yet available.', 503);
        } catch (OidcCallbackException $e) {
            return response($e->getUserFacingMessage(), $e->getStatusCode());
        }
    }
}
