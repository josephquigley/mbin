<?php

declare(strict_types=1);

namespace App\Service\Oidc;

use Firebase\JWT\CachedKeySet;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * Builds the id_token validator against the provider's live key set.
 *
 * CachedKeySet caches the JWKS, refetches it when a token arrives with an
 * unknown `kid` (which is what key rotation looks like from here) and rate
 * limits itself so that a bogus `kid` cannot turn into a fetch loop.
 *
 * The key set is created lazily inside the closure, so that resolving the
 * provider metadata, and therefore any discovery request, happens on the first
 * login attempt rather than while the container is warming up.
 */
class OidcValidatorFactory
{
    public function __construct(
        private readonly OidcMetadataResolver $metadataResolver,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly CacheItemPoolInterface $cache,
        private readonly string $clientId,
        private readonly string $issuer,
    ) {
    }

    public function create(): OidcTokenValidator
    {
        $metadataResolver = $this->metadataResolver;
        $httpClient = $this->httpClient;
        $requestFactory = $this->requestFactory;
        $cache = $this->cache;

        $keySetResolver = static fn (): CachedKeySet => new CachedKeySet(
            $metadataResolver->resolve()->jwksUri,
            $httpClient,
            $requestFactory,
            $cache,
            null,
            true,
        );

        return new OidcTokenValidator($keySetResolver, $this->issuer, $this->clientId);
    }
}
