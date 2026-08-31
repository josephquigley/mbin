<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ActivityPub;

use App\Service\ActivityPub\ApHttpClient;
use PHPUnit\Framework\TestCase;

class ApHttpClientTest extends TestCase
{
    private function requestTarget(string $url, string $method = 'get'): string
    {
        $reflection = new \ReflectionMethod(ApHttpClient::class, 'headersToSign');
        $headers = $reflection->invoke(null, $url, null, $method);

        return $headers['(request-target)'];
    }

    public function testItSignsThePathOfAUrlWithoutAQueryString(): void
    {
        self::assertSame(
            'get /u/user',
            $this->requestTarget('https://kbin.localhost/u/user')
        );
    }

    /**
     * The HTTP Signatures draft builds (request-target) from the lowercased method and the
     * :path pseudo-header, which carries the path and the query. Dropping the query means
     * signing a different request target from the one that goes on the wire.
     */
    public function testItSignsThePathAndQueryOfAUrlWithAQueryString(): void
    {
        self::assertSame(
            'get /.well-known/webfinger?resource=acct:user@kbin.localhost',
            $this->requestTarget('https://kbin.localhost/.well-known/webfinger?resource=acct:user@kbin.localhost')
        );
    }

    public function testItSignsThePathAndQueryOfAPaginatedCollectionUrl(): void
    {
        self::assertSame(
            'get /u/user/followers?page=2',
            $this->requestTarget('https://kbin.localhost/u/user/followers?page=2')
        );
    }

    public function testItSignsAPostRequestTargetWithItsQueryString(): void
    {
        self::assertSame(
            'post /f/inbox?foo=bar',
            $this->requestTarget('https://kbin.localhost/f/inbox?foo=bar', 'post')
        );
    }

    public function testItSignsARootUrlWithAQueryStringAsASlash(): void
    {
        self::assertSame(
            'get /?page=2',
            $this->requestTarget('https://kbin.localhost/?page=2')
        );
    }
}
