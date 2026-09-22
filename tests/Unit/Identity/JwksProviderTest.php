<?php

namespace Tests\Unit\Identity;

use App\Modules\Identity\Oidc\Exceptions\OidcCallbackException;
use App\Modules\Identity\Oidc\JwksProvider;
use Firebase\JWT\Key;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JwksProviderTest extends TestCase
{
    private JwksProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'oidc.issuer' => 'https://auth.reltroner.com/realms/reltroner',
            'oidc.http_timeout' => 5,
            'oidc.jwks_cache_ttl' => 3600,
        ]);

        Cache::flush();
        $this->provider = new JwksProvider();
    }

    private function sampleRsaJwk(string $kid = 'test-kid', array $overrides = []): array
    {
        return array_merge([
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $kid,
            'n' => 'wdXNzJlc8smH6dh5ZmFxSe4zgcdmjCeCr0zBOr2GdYuewhENYYcltrkoBpNrXMJa6QR-hVMjdK0F1PTmV1djFO4e5n2Khopjm_L6Gn92p8SD9o96_kl2HJGvLeBSpHOjJEBSk_xy-PX8K_pAZz4_weCZrn-dPgPZT0oxqg6MV1iJ-Bhyr5CoE51KjJbUcVUcNAamX70xW2R7-pLUnRI3YbE-HIPUEZSeL6EWyXtYMPZICN_U030IEraCT-mzCjR7Rr5lF1GVhyfKJlcpsI4Z81nsJRTzyGZe032To-wYvcxHEg-0_qe76GC6GFt3EnSj2zDAeNt_Wh9-myPrr9EAOw',
            'e' => 'AQAB',
        ], $overrides);
    }

    public function test_cached_kid_resolves_without_http_request(): void
    {
        $cacheKey = 'oidc:jwks:' . hash('sha256', 'https://auth.reltroner.com/realms/reltroner');
        Cache::put($cacheKey, ['keys' => [$this->sampleRsaJwk('cached-kid')]], 3600);

        Http::fake();

        $key = $this->provider->getKeyByKid('cached-kid');

        $this->assertInstanceOf(Key::class, $key);
        $this->assertSame('RS256', $key->getAlgorithm());
        Http::assertNothingSent();
    }

    public function test_cache_miss_fetches_once_and_caches(): void
    {
        $certsUrl = 'https://auth.reltroner.com/realms/reltroner/protocol/openid-connect/certs';
        Http::fake([
            $certsUrl => Http::response(['keys' => [$this->sampleRsaJwk('fresh-kid')]], 200),
        ]);

        $key = $this->provider->getKeyByKid('fresh-kid');

        $this->assertInstanceOf(Key::class, $key);
        $this->assertSame('RS256', $key->getAlgorithm());
        Http::assertSentCount(1);

        $cacheKey = 'oidc:jwks:' . hash('sha256', 'https://auth.reltroner.com/realms/reltroner');
        $this->assertTrue(Cache::has($cacheKey));
        $cached = Cache::get($cacheKey);
        $this->assertSame('fresh-kid', $cached['keys'][0]['kid']);
    }

    public function test_unknown_cached_kid_triggers_exactly_one_refresh_and_resolves(): void
    {
        $cacheKey = 'oidc:jwks:' . hash('sha256', 'https://auth.reltroner.com/realms/reltroner');
        Cache::put($cacheKey, ['keys' => [$this->sampleRsaJwk('old-kid')]], 3600);

        $certsUrl = 'https://auth.reltroner.com/realms/reltroner/protocol/openid-connect/certs';
        Http::fake([
            $certsUrl => Http::response([
                'keys' => [
                    $this->sampleRsaJwk('old-kid'),
                    $this->sampleRsaJwk('rotated-kid'),
                ],
            ], 200),
        ]);

        $key = $this->provider->getKeyByKid('rotated-kid');

        $this->assertInstanceOf(Key::class, $key);
        Http::assertSentCount(1);

        $cached = Cache::get($cacheKey);
        $this->assertCount(2, $cached['keys']);
    }

    public function test_still_unknown_kid_after_refresh_fails_closed(): void
    {
        $cacheKey = 'oidc:jwks:' . hash('sha256', 'https://auth.reltroner.com/realms/reltroner');
        Cache::put($cacheKey, ['keys' => [$this->sampleRsaJwk('old-kid')]], 3600);

        $certsUrl = 'https://auth.reltroner.com/realms/reltroner/protocol/openid-connect/certs';
        Http::fake([
            $certsUrl => Http::response(['keys' => [$this->sampleRsaJwk('different-kid')]], 200),
        ]);

        try {
            $this->provider->getKeyByKid('non-existent-kid');
            $this->fail('Expected OidcCallbackException was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('unknown_kid', $e->getCategory());
        }

        Http::assertSentCount(1);
    }

    public function test_malformed_cached_jwks_refreshes_and_resolves(): void
    {
        $cacheKey = 'oidc:jwks:' . hash('sha256', 'https://auth.reltroner.com/realms/reltroner');
        Cache::put($cacheKey, 'malformed-string-in-cache', 3600);

        $certsUrl = 'https://auth.reltroner.com/realms/reltroner/protocol/openid-connect/certs';
        Http::fake([
            $certsUrl => Http::response(['keys' => [$this->sampleRsaJwk('fresh-kid')]], 200),
        ]);

        $key = $this->provider->getKeyByKid('fresh-kid');

        $this->assertInstanceOf(Key::class, $key);
        Http::assertSentCount(1);
    }

    public function test_malformed_fresh_jwks_fails_closed_with_502(): void
    {
        $certsUrl = 'https://auth.reltroner.com/realms/reltroner/protocol/openid-connect/certs';
        Http::fake([
            $certsUrl => Http::response(['not_keys' => []], 200),
        ]);

        try {
            $this->provider->getKeyByKid('any-kid');
            $this->fail('Expected OidcCallbackException was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(502, $e->getStatusCode());
            $this->assertSame('jwks_malformed', $e->getCategory());
        }
    }

    public function test_rsa_signing_key_is_accepted(): void
    {
        $certsUrl = 'https://auth.reltroner.com/realms/reltroner/protocol/openid-connect/certs';
        Http::fake([
            $certsUrl => Http::response(['keys' => [$this->sampleRsaJwk('sig-kid', ['use' => 'sig', 'alg' => 'RS256'])]], 200),
        ]);

        $key = $this->provider->getKeyByKid('sig-kid');

        $this->assertInstanceOf(Key::class, $key);
    }

    public function test_encryption_only_key_is_rejected(): void
    {
        $certsUrl = 'https://auth.reltroner.com/realms/reltroner/protocol/openid-connect/certs';
        Http::fake([
            $certsUrl => Http::response(['keys' => [$this->sampleRsaJwk('enc-kid', ['use' => 'enc'])]], 200),
        ]);

        try {
            $this->provider->getKeyByKid('enc-kid');
            $this->fail('Expected OidcCallbackException was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('invalid_key_use', $e->getCategory());
        }
    }

    public function test_wrong_kty_is_rejected(): void
    {
        $certsUrl = 'https://auth.reltroner.com/realms/reltroner/protocol/openid-connect/certs';
        Http::fake([
            $certsUrl => Http::response(['keys' => [$this->sampleRsaJwk('ec-kid', ['kty' => 'EC'])]], 200),
        ]);

        try {
            $this->provider->getKeyByKid('ec-kid');
            $this->fail('Expected OidcCallbackException was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('invalid_kty', $e->getCategory());
        }
    }

    public function test_wrong_alg_is_rejected(): void
    {
        $certsUrl = 'https://auth.reltroner.com/realms/reltroner/protocol/openid-connect/certs';
        Http::fake([
            $certsUrl => Http::response(['keys' => [$this->sampleRsaJwk('es256-kid', ['alg' => 'ES256'])]], 200),
        ]);

        try {
            $this->provider->getKeyByKid('es256-kid');
            $this->fail('Expected OidcCallbackException was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('invalid_key_alg', $e->getCategory());
        }
    }

    public function test_no_token_or_secret_sent_to_jwks_endpoint(): void
    {
        $certsUrl = 'https://auth.reltroner.com/realms/reltroner/protocol/openid-connect/certs';
        Http::fake([
            $certsUrl => Http::response(['keys' => [$this->sampleRsaJwk('safe-kid')]], 200),
        ]);

        $this->provider->getKeyByKid('safe-kid');

        Http::assertSent(function (Request $request) use ($certsUrl) {
            $this->assertSame($certsUrl, $request->url());
            $this->assertFalse($request->hasHeader('Authorization'));
            $this->assertEmpty($request->data());
            return true;
        });
    }

    public function test_cached_matching_but_invalid_jwk_refreshes_once_and_uses_fresh_valid_key(): void
    {
        $cacheKey = 'oidc:jwks:' . hash('sha256', 'https://auth.reltroner.com/realms/reltroner');
        Cache::put($cacheKey, ['keys' => [$this->sampleRsaJwk('key-1', ['use' => 'enc'])]], 3600);

        $certsUrl = 'https://auth.reltroner.com/realms/reltroner/protocol/openid-connect/certs';
        Http::fake([
            $certsUrl => Http::response(['keys' => [$this->sampleRsaJwk('key-1', ['use' => 'sig', 'alg' => 'RS256'])]], 200),
        ]);

        $key = $this->provider->getKeyByKid('key-1');

        $this->assertInstanceOf(Key::class, $key);
        $this->assertSame('RS256', $key->getAlgorithm());
        Http::assertSentCount(1);

        $cached = Cache::get($cacheKey);
        $this->assertSame('sig', $cached['keys'][0]['use']);
    }

    public function test_cached_matching_invalid_jwk_with_invalid_fresh_jwk_fails_after_one_refresh(): void
    {
        $cacheKey = 'oidc:jwks:' . hash('sha256', 'https://auth.reltroner.com/realms/reltroner');
        Cache::put($cacheKey, ['keys' => [$this->sampleRsaJwk('key-bad', ['use' => 'enc'])]], 3600);

        $certsUrl = 'https://auth.reltroner.com/realms/reltroner/protocol/openid-connect/certs';
        Http::fake([
            $certsUrl => Http::response(['keys' => [$this->sampleRsaJwk('key-bad', ['use' => 'enc'])]], 200),
        ]);

        try {
            $this->provider->getKeyByKid('key-bad');
            $this->fail('Expected OidcCallbackException was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('invalid_key_use', $e->getCategory());
        }

        Http::assertSentCount(1);
    }
}
