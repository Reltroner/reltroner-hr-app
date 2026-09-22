<?php

namespace Tests\Unit\Identity;

use App\Modules\Identity\Oidc\OidcPrimitives;
use PHPUnit\Framework\TestCase;

class OidcPrimitivesTest extends TestCase
{
    /**
     * 1. Prove generated verifier length and charset (43 chars, [A-Za-z0-9_-]).
     */
    public function test_generated_code_verifier_length_and_charset(): void
    {
        $verifier = OidcPrimitives::generateCodeVerifier();

        $this->assertSame(43, strlen($verifier));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $verifier);
    }

    /**
     * 2. Prove generated state length and charset (43 chars, [A-Za-z0-9_-]).
     */
    public function test_generated_state_length_and_charset(): void
    {
        $state = OidcPrimitives::generateState();

        $this->assertSame(43, strlen($state));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $state);
    }

    /**
     * 3. Prove generated nonce length and charset (43 chars, [A-Za-z0-9_-]).
     */
    public function test_generated_nonce_length_and_charset(): void
    {
        $nonce = OidcPrimitives::generateNonce();

        $this->assertSame(43, strlen($nonce));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $nonce);
    }

    /**
     * 4. Prove consecutive state values differ (high entropy / collision-free).
     */
    public function test_consecutive_state_values_differ(): void
    {
        $state1 = OidcPrimitives::generateState();
        $state2 = OidcPrimitives::generateState();

        $this->assertNotSame($state1, $state2);
    }

    /**
     * 5. Prove consecutive nonce values differ (high entropy / collision-free).
     */
    public function test_consecutive_nonce_values_differ(): void
    {
        $nonce1 = OidcPrimitives::generateNonce();
        $nonce2 = OidcPrimitives::generateNonce();

        $this->assertNotSame($nonce1, $nonce2);
    }

    /**
     * 6. Prove RFC 7636 Appendix B S256 vector matches exactly.
     *
     * Example verifier: dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk
     * Expected challenge: E9Melhoa2OwvFrGMTJguCH5qmG136cv11WNTsgn6WUw
     */
    public function test_rfc7636_appendix_b_s256_vector_matches_exactly(): void
    {
        $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $expectedChallenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

        $computedChallenge = OidcPrimitives::codeChallengeS256($verifier);

        $this->assertSame($expectedChallenge, $computedChallenge);
    }

    /**
     * 7. Prove Base64URL output is unpadded and URL-safe (no '=', '+', or '/').
     */
    public function test_base64url_output_is_unpadded_and_url_safe(): void
    {
        // Binary test data with bytes that produce '+', '/', and padding '=' in standard base64
        // Example: hex 'fbff' in standard base64 is '+/8='
        $binary = hex2bin('fbff');
        $this->assertNotFalse($binary);

        $encoded = OidcPrimitives::base64UrlEncode($binary);

        $this->assertStringNotContainsString('+', $encoded);
        $this->assertStringNotContainsString('/', $encoded);
        $this->assertStringNotContainsString('=', $encoded);
        $this->assertSame('-_8', $encoded);
    }
}
