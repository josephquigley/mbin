<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Oidc;

use App\Service\Oidc\Exception\OidcConfigurationException;
use App\Service\Oidc\OidcMetadataResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

class OidcMetadataResolverTest extends TestCase
{
    private const DISCOVERY = [
        'authorization_endpoint' => 'https://idp.test/authorize',
        'token_endpoint' => 'https://idp.test/api/oidc/token',
        'userinfo_endpoint' => 'https://idp.test/api/oidc/userinfo',
        'jwks_uri' => 'https://idp.test/.well-known/jwks.json',
    ];

    public function testDiscoversEveryEndpoint(): void
    {
        $client = new MockHttpClient([new JsonMockResponse(self::DISCOVERY)]);
        $metadata = $this->resolver($client)->resolve();

        self::assertSame('https://idp.test', $metadata->issuer);
        self::assertSame('https://idp.test/authorize', $metadata->authorizationEndpoint);
        self::assertSame('https://idp.test/api/oidc/token', $metadata->tokenEndpoint);
        self::assertSame('https://idp.test/api/oidc/userinfo', $metadata->userinfoEndpoint);
        self::assertSame('https://idp.test/.well-known/jwks.json', $metadata->jwksUri);
    }

    public function testFullOverrideMakesNoHttpCall(): void
    {
        $client = new MockHttpClient(function (): MockResponse {
            self::fail('discovery must not run when every endpoint is configured');
        });

        $metadata = $this->resolver($client, [
            'authorize' => 'https://idp.test/a',
            'token' => 'https://idp.test/t',
            'userinfo' => 'https://idp.test/u',
            'jwks' => 'https://idp.test/j',
        ])->resolve();

        self::assertSame('https://idp.test/u', $metadata->userinfoEndpoint);
    }

    public function testPartialOverrideWinsOverDiscovery(): void
    {
        $client = new MockHttpClient([new JsonMockResponse(self::DISCOVERY)]);
        $metadata = $this->resolver($client, ['userinfo' => 'http://internal:1411/api/oidc/userinfo'])->resolve();

        self::assertSame('http://internal:1411/api/oidc/userinfo', $metadata->userinfoEndpoint);
        self::assertSame('https://idp.test/authorize', $metadata->authorizationEndpoint);
    }

    public function testEmptyStringIsTreatedAsUnset(): void
    {
        $client = new MockHttpClient([new JsonMockResponse(self::DISCOVERY)]);
        $metadata = $this->resolver($client, ['authorize' => '', 'token' => '', 'userinfo' => '', 'jwks' => ''])->resolve();

        self::assertSame('https://idp.test/authorize', $metadata->authorizationEndpoint);
    }

    public function testMissingIssuerIsRejected(): void
    {
        $this->expectException(OidcConfigurationException::class);
        $this->expectExceptionMessageMatches('/OAUTH_OIDC_ISSUER/');

        $this->resolver(new MockHttpClient([]), [], '')->resolve();
    }

    public function testIsConfiguredReportsAnEmptyIssuer(): void
    {
        self::assertFalse($this->resolver(new MockHttpClient([]), [], '')->isConfigured());
        self::assertTrue($this->resolver(new MockHttpClient([]))->isConfigured());
    }

    public function testDiscoveryFailureNamesTheUrlItTried(): void
    {
        $client = new MockHttpClient([new MockResponse('nope', ['http_code' => 404])]);

        $this->expectException(OidcConfigurationException::class);
        $this->expectExceptionMessageMatches('#https://idp\.test/\.well-known/openid-configuration#');

        $this->resolver($client)->resolve();
    }

    public function testAnIncompleteDiscoveryDocumentNamesTheMissingKeys(): void
    {
        $client = new MockHttpClient([new JsonMockResponse(['authorization_endpoint' => 'https://idp.test/authorize'])]);

        $this->expectException(OidcConfigurationException::class);
        $this->expectExceptionMessageMatches('/OAUTH_OIDC_JWKS_URL/');

        $this->resolver($client)->resolve();
    }

    public function testDiscoveryResultIsCached(): void
    {
        $client = new MockHttpClient([new JsonMockResponse(self::DISCOVERY)]);
        $cache = new ArrayAdapter();

        $first = new OidcMetadataResolver($client, $cache, 'https://idp.test', null, null, null, null);
        $second = new OidcMetadataResolver($client, $cache, 'https://idp.test', null, null, null, null);

        self::assertSame($first->resolve()->tokenEndpoint, $second->resolve()->tokenEndpoint);
        self::assertSame(1, $client->getRequestsCount());
    }

    /**
     * @param array<string, string> $overrides
     */
    private function resolver(
        MockHttpClient $client,
        array $overrides = [],
        string $issuer = 'https://idp.test',
    ): OidcMetadataResolver {
        return new OidcMetadataResolver(
            $client,
            new ArrayAdapter(),
            $issuer,
            $overrides['authorize'] ?? null,
            $overrides['token'] ?? null,
            $overrides['userinfo'] ?? null,
            $overrides['jwks'] ?? null,
        );
    }
}
