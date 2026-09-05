<?php

declare(strict_types=1);

namespace App\Tests\Unit\Provider;

use App\Provider\Oidc;
use App\Service\Oidc\OidcMetadataResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;

class OidcScopesTest extends TestCase
{
    public function testTheGroupsScopeIsNotRequestedByDefault(): void
    {
        self::assertStringNotContainsString('groups', $this->authorizationUrl(null));
    }

    public function testTheGroupsScopeIsRequestedWhenAnAdminGroupIsConfigured(): void
    {
        self::assertStringContainsString('groups', $this->authorizationUrl('mbin-admins'));
    }

    public function testAWhitespaceAdminGroupDoesNotRequestTheScope(): void
    {
        self::assertStringNotContainsString('groups', $this->authorizationUrl('   '));
    }

    private function authorizationUrl(?string $adminGroup): string
    {
        $client = new MockHttpClient([]);

        $provider = new Oidc([
            'clientId' => 'mbin',
            'clientSecret' => 'secret',
            'redirectUri' => 'https://mbin.test/oauth/oidc/verify',
            'metadata_resolver' => new OidcMetadataResolver(
                $client,
                new ArrayAdapter(),
                'https://idp.test',
                'https://idp.test/authorize',
                'https://idp.test/token',
                'https://idp.test/userinfo',
                'https://idp.test/jwks',
            ),
            'username_claim' => 'preferred_username',
            'admin_group' => $adminGroup,
        ]);

        return $provider->getAuthorizationUrl();
    }
}
