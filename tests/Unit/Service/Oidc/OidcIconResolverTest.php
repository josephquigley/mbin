<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Oidc;

use App\Service\Oidc\OidcIconResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class OidcIconResolverTest extends TestCase
{
    private const PNG = "\x89PNG\r\n\x1a\n-pretend-this-is-an-icon";

    public function testReturnsADataUri(): void
    {
        $client = new MockHttpClient([
            new MockResponse(self::PNG, ['response_headers' => ['content-type' => 'image/png']]),
        ]);

        $icon = $this->resolver($client)->resolve();

        self::assertSame('data:image/png;base64,'.base64_encode(self::PNG), $icon);
    }

    public function testFetchesFaviconFromTheIssuerRoot(): void
    {
        $seen = null;
        $client = new MockHttpClient(function (string $method, string $url) use (&$seen): MockResponse {
            $seen = $url;

            return new MockResponse(self::PNG, ['response_headers' => ['content-type' => 'image/png']]);
        });

        $this->resolver($client, issuer: 'https://idp.test/')->resolve();

        self::assertSame('https://idp.test/favicon.ico', $seen);
    }

    public function testNonImageContentTypeIsRejected(): void
    {
        $client = new MockHttpClient([
            new MockResponse('<html>not found</html>', ['response_headers' => ['content-type' => 'text/html']]),
            new MockResponse('<html><head></head></html>', ['response_headers' => ['content-type' => 'text/html']]),
        ]);

        self::assertNull($this->resolver($client)->resolve());
    }

    /**
     * The case a single-page IdP actually presents: /favicon.ico answers 200
     * with the application shell, and the real icon is named by the shell's
     * own link tag. Pocket ID behaves exactly like this.
     */
    public function testFallsBackToTheDeclaredLinkRelIcon(): void
    {
        $shell = '<html><head><link rel="icon" href="/api/application-images/favicon" />'
            .'<link rel="apple-touch-icon" href="/img/logo.png" /></head></html>';

        $requested = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requested, $shell): MockResponse {
            $requested[] = $url;

            return match (\count($requested)) {
                1 => new MockResponse($shell, ['response_headers' => ['content-type' => 'text/html']]),
                2 => new MockResponse($shell, ['response_headers' => ['content-type' => 'text/html']]),
                default => new MockResponse(self::PNG, ['response_headers' => ['content-type' => 'image/x-icon']]),
            };
        });

        $icon = $this->resolver($client)->resolve();

        self::assertSame('data:image/x-icon;base64,'.base64_encode(self::PNG), $icon);
        self::assertSame([
            'https://idp.test/favicon.ico',
            'https://idp.test/',
            'https://idp.test/api/application-images/favicon',
        ], $requested);
    }

    public function testADeclaredIconOnAnotherOriginIsNotFollowed(): void
    {
        $shell = '<html><head><link rel="icon" href="http://169.254.169.254/latest/meta-data" /></head></html>';

        $requested = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requested, $shell): MockResponse {
            $requested[] = $url;

            return new MockResponse($shell, ['response_headers' => ['content-type' => 'text/html']]);
        });

        self::assertNull($this->resolver($client)->resolve());
        self::assertSame(['https://idp.test/favicon.ico', 'https://idp.test/'], $requested);
    }

    public function testAbsoluteSameOriginHrefIsFollowed(): void
    {
        $shell = '<html><head><link rel="shortcut icon" href="https://idp.test/brand/mark.png"></head></html>';

        $requested = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requested, $shell): MockResponse {
            $requested[] = $url;

            return match (\count($requested)) {
                1, 2 => new MockResponse($shell, ['response_headers' => ['content-type' => 'text/html']]),
                default => new MockResponse(self::PNG, ['response_headers' => ['content-type' => 'image/png']]),
            };
        });

        self::assertNotNull($this->resolver($client)->resolve());
        self::assertSame('https://idp.test/brand/mark.png', $requested[2]);
    }

    public function testAnOversizedBodyIsRejected(): void
    {
        $client = new MockHttpClient([
            new MockResponse(str_repeat('x', 40000), ['response_headers' => ['content-type' => 'image/png']]),
            new MockResponse('<html></html>', ['response_headers' => ['content-type' => 'text/html']]),
        ]);

        self::assertNull($this->resolver($client)->resolve());
    }

    public function testAnErrorStatusIsRejected(): void
    {
        $client = new MockHttpClient([
            new MockResponse('nope', ['http_code' => 404, 'response_headers' => ['content-type' => 'image/png']]),
            new MockResponse('nope', ['http_code' => 404]),
        ]);

        self::assertNull($this->resolver($client)->resolve());
    }

    public function testATransportFailureIsSwallowed(): void
    {
        $client = new MockHttpClient(function (): MockResponse {
            throw new \RuntimeException('connection refused');
        });

        self::assertNull($this->resolver($client)->resolve());
    }

    public function testDisabledMakesNoRequest(): void
    {
        $client = new MockHttpClient(function (): MockResponse {
            self::fail('no request may be made when icon fetching is disabled');
        });

        self::assertNull($this->resolver($client, fetchIcon: 'false')->resolve());
    }

    #[DataProvider('disablingValues')]
    public function testOnlyExplicitFalseValuesDisableIt(string $value, bool $expected): void
    {
        self::assertSame($expected, $this->resolver(new MockHttpClient([]), fetchIcon: $value)->isEnabled());
    }

    /**
     * @return \Generator<array{string, bool}>
     */
    public static function disablingValues(): \Generator
    {
        yield ['false', false];
        yield ['FALSE', false];
        yield ['0', false];
        yield ['off', false];
        yield ['no', false];
        yield ['', true];
        yield ['true', true];
        yield ['1', true];
    }

    public function testAnUnsetIssuerReturnsNull(): void
    {
        self::assertNull($this->resolver(new MockHttpClient([]), issuer: null)->resolve());
    }

    public function testTheResultIsCached(): void
    {
        $client = new MockHttpClient([
            new MockResponse(self::PNG, ['response_headers' => ['content-type' => 'image/png']]),
        ]);
        $cache = new ArrayAdapter();

        self::assertNotNull($this->resolver($client, cache: $cache)->resolve());
        self::assertNotNull($this->resolver($client, cache: $cache)->resolve());
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testAFailureIsCachedToo(): void
    {
        $client = new MockHttpClient([
            new MockResponse('nope', ['http_code' => 404]),
            new MockResponse('nope', ['http_code' => 404]),
        ]);
        $cache = new ArrayAdapter();

        self::assertNull($this->resolver($client, cache: $cache)->resolve());
        self::assertNull($this->resolver($client, cache: $cache)->resolve());
        self::assertSame(2, $client->getRequestsCount());
    }

    private function resolver(
        MockHttpClient $client,
        ?string $issuer = 'https://idp.test',
        ?string $fetchIcon = null,
        ?ArrayAdapter $cache = null,
    ): OidcIconResolver {
        $cache ??= new ArrayAdapter();

        return new OidcIconResolver(
            $client,
            $cache,
            $issuer,
            $fetchIcon,
        );
    }
}
