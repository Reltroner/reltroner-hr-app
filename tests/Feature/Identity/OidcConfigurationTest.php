<?php

namespace Tests\Feature\Identity;

use Tests\TestCase;

class OidcConfigurationTest extends TestCase
{
    /**
     * 1. Prove oidc config is registered.
     */
    public function test_oidc_config_is_registered(): void
    {
        $config = config('oidc');

        $this->assertIsArray($config);
    }

    /**
     * 2. Prove scopes default exactly to 'openid profile email'.
     */
    public function test_scopes_default_exactly_to_openid_profile_email(): void
    {
        $this->assertSame('openid profile email', config('oidc.scopes'));
    }

    /**
     * 3. Prove transaction TTL default is 300.
     */
    public function test_transaction_ttl_default_is_300(): void
    {
        $this->assertSame(300, config('oidc.transaction_ttl'));
    }

    /**
     * 4. Prove PKCE method is S256.
     */
    public function test_pkce_method_is_s256(): void
    {
        $this->assertSame('S256', config('oidc.pkce_method'));
    }

    /**
     * 5. Prove deployment-specific settings remain unset/null when environment variables are absent.
     */
    public function test_deployment_specific_settings_remain_unset_or_null_without_env(): void
    {
        // Without environment variables set, these critical deployment settings must be null/empty
        $this->assertNull(config('oidc.issuer'));
        $this->assertNull(config('oidc.client_id'));
        $this->assertNull(config('oidc.client_secret'));
        $this->assertNull(config('oidc.redirect_uri'));
        $this->assertNull(config('oidc.environment'));
        $this->assertNull(config('oidc.expected_identity_class'));
    }

    /**
     * 6. Prove no client secret is hardcoded.
     */
    public function test_no_client_secret_is_hardcoded(): void
    {
        $configFile = file_get_contents(config_path('oidc.php'));
        $this->assertNotFalse($configFile);

        // Verify config uses env() for client_secret without a hardcoded fallback string
        $this->assertMatchesRegularExpression("/'client_secret'\s*=>\s*env\(\s*'OIDC_CLIENT_SECRET'\s*\)/", $configFile);
        $this->assertNull(config('oidc.client_secret'));
    }
}
