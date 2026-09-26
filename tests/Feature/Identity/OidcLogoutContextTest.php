<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Oidc\OidcLogoutContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class OidcLogoutContextTest extends TestCase
{
    private function createSessionRequest(array $sessionData = []): Request
    {
        $request = Request::create('/', 'GET');
        $session = $this->app['session']->driver();
        $session->start();
        $session->flush();

        foreach ($sessionData as $key => $value) {
            $session->put($key, $value);
        }

        $request->setLaravelSession($session);

        return $request;
    }

    public function test_store_keeps_only_encrypted_hint_and_pull_returns_it_once(): void
    {
        $request = $this->createSessionRequest();
        $idTokenHint = 'header.payload.signature';

        OidcLogoutContext::store($request, $idTokenHint);

        $payload = $request->session()->get(OidcLogoutContext::SESSION_KEY);

        $this->assertIsArray($payload);
        $this->assertSame([
            OidcLogoutContext::ENCRYPTED_ID_TOKEN_HINT_KEY,
        ], array_keys($payload));
        $this->assertNotSame(
            $idTokenHint,
            $payload[OidcLogoutContext::ENCRYPTED_ID_TOKEN_HINT_KEY]
        );
        $this->assertStringNotContainsString(
            $idTokenHint,
            json_encode($payload)
        );

        $this->assertSame(
            $idTokenHint,
            OidcLogoutContext::pullIdTokenHint($request)
        );
        $this->assertFalse(OidcLogoutContext::has($request));
        $this->assertNull(OidcLogoutContext::pullIdTokenHint($request));
    }

    public function test_corrupt_encrypted_context_falls_back_with_generic_log_only(): void
    {
        $request = $this->createSessionRequest([
            OidcLogoutContext::SESSION_KEY => [
                OidcLogoutContext::ENCRYPTED_ID_TOKEN_HINT_KEY => 'corrupt-sensitive-looking-material',
            ],
        ]);

        Log::spy();

        $this->assertNull(OidcLogoutContext::pullIdTokenHint($request));
        $this->assertFalse(OidcLogoutContext::has($request));

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('oidc.logout_context.invalid');
    }

    public function test_malformed_context_shape_falls_back_without_throwing(): void
    {
        $request = $this->createSessionRequest([
            OidcLogoutContext::SESSION_KEY => [
                'unexpected_key' => 'unexpected-value',
            ],
        ]);

        Log::spy();

        $this->assertNull(OidcLogoutContext::pullIdTokenHint($request));
        $this->assertFalse(OidcLogoutContext::has($request));

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('oidc.logout_context.invalid');
    }

    public function test_missing_context_returns_null_without_warning(): void
    {
        $request = $this->createSessionRequest();

        Log::spy();

        $this->assertNull(OidcLogoutContext::pullIdTokenHint($request));

        Log::shouldNotHaveReceived('warning');
    }
}
