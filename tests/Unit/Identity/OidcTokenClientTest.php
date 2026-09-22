<?php

namespace Tests\Unit\Identity;

use App\Modules\Identity\Oidc\Exceptions\OidcCallbackException;
use App\Modules\Identity\Oidc\OidcTokenClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OidcTokenClientTest extends TestCase
{
    protected OidcTokenClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'oidc.issuer' => 'https://auth.reltroner.com/realms/reltroner',
            'oidc.client_id' => 'hrm-web',
            'oidc.client_secret' => 'confidential-secret-123',
            'oidc.redirect_uri' => 'https://hrm.reltroner.com/auth/keycloak/callback',
            'oidc.http_timeout' => 5,
        ]);

        $this->client = new OidcTokenClient();
    }

    /**
     * Prove token client sends exact expected POST request with Basic Auth and no client_id in body.
     */
    public function test_sends_correct_http_basic_request_and_returns_id_token(): void
    {
        Http::fake([
            'https://auth.reltroner.com/realms/reltroner/protocol/openid-connect/token' => Http::response([
                'id_token' => 'sample.jwt.token',
                'access_token' => 'sample.access.token',
                'token_type' => 'Bearer',
                'expires_in' => 300,
            ], 200),
        ]);

        $idToken = $this->client->exchangeCode('auth-code-123', 'verifier-456');

        $this->assertSame('sample.jwt.token', $idToken);

        Http::assertSent(function (Request $request) {
            $hasBasicAuth = $request->hasHeader('Authorization', 'Basic ' . base64_encode('hrm-web:confidential-secret-123'));
            $isForm = str_contains($request->header('Content-Type')[0] ?? '', 'application/x-www-form-urlencoded');
            $body = $request->data();

            return $request->url() === 'https://auth.reltroner.com/realms/reltroner/protocol/openid-connect/token'
                && $request->method() === 'POST'
                && $hasBasicAuth
                && $isForm
                && ($body['grant_type'] ?? null) === 'authorization_code'
                && ($body['code'] ?? null) === 'auth-code-123'
                && ($body['redirect_uri'] ?? null) === 'https://hrm.reltroner.com/auth/keycloak/callback'
                && ($body['code_verifier'] ?? null) === 'verifier-456'
                && !isset($body['client_id']); // client_id MUST NOT be in body
        });
    }

    /**
     * Prove non-2xx status code throws OidcCallbackException mapped to 502.
     */
    public function test_non_2xx_response_throws_502(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $this->expectException(OidcCallbackException::class);

        try {
            $this->client->exchangeCode('bad-code', 'verifier-456');
        } catch (OidcCallbackException $e) {
            $this->assertSame(502, $e->getStatusCode());
            $this->assertSame('token_http_error', $e->getCategory());
            throw $e;
        }
    }

    /**
     * Prove connection failure / exception maps to 502.
     */
    public function test_connection_failure_throws_502(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
        });

        $this->expectException(OidcCallbackException::class);

        try {
            $this->client->exchangeCode('auth-code-123', 'verifier-456');
        } catch (OidcCallbackException $e) {
            $this->assertSame(502, $e->getStatusCode());
            $this->assertSame('token_connection_failed', $e->getCategory());
            throw $e;
        }
    }

    /**
     * Prove malformed JSON response throws 502.
     */
    public function test_malformed_json_response_throws_502(): void
    {
        Http::fake([
            '*' => Http::response('<html>Not JSON</html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $this->expectException(OidcCallbackException::class);

        try {
            $this->client->exchangeCode('auth-code-123', 'verifier-456');
        } catch (OidcCallbackException $e) {
            $this->assertSame(502, $e->getStatusCode());
            $this->assertSame('malformed_token_response', $e->getCategory());
            throw $e;
        }
    }

    /**
     * Prove missing id_token in 200 response throws 502.
     */
    public function test_missing_id_token_throws_502(): void
    {
        Http::fake([
            '*' => Http::response([
                'access_token' => 'sample.access.token',
                'token_type' => 'Bearer',
            ], 200),
        ]);

        $this->expectException(OidcCallbackException::class);

        try {
            $this->client->exchangeCode('auth-code-123', 'verifier-456');
        } catch (OidcCallbackException $e) {
            $this->assertSame(502, $e->getStatusCode());
            $this->assertSame('malformed_token_response', $e->getCategory());
            throw $e;
        }
    }

    /**
     * Prove no automatic retry occurs on token exchange failure.
     */
    public function test_no_automatic_retry_behavior(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'server_error'], 500),
        ]);

        try {
            $this->client->exchangeCode('auth-code-123', 'verifier-456');
        } catch (OidcCallbackException $e) {
            // Expected
        }

        Http::assertSentCount(1);
    }
}
