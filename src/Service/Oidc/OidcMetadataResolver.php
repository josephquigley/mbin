<?php

declare(strict_types=1);

namespace App\Service\Oidc;

use App\Service\Oidc\Exception\OidcConfigurationException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Resolves the endpoints of the configured OIDC provider.
 *
 * The issuer is always required, because validating the `iss` claim of an
 * id_token is the point of the exercise. The four endpoints are discovered
 * from the issuer's `.well-known/openid-configuration` document, and any of
 * them may be overridden by configuration: an admin whose provider publishes
 * an address this instance cannot reach needs exactly that. Discovery is
 * skipped altogether when all four are configured.
 */
class OidcMetadataResolver
{
    private const CACHE_TTL = 86400;

    private ?string $issuer;
    private ?string $authorizationEndpoint;
    private ?string $tokenEndpoint;
    private ?string $userinfoEndpoint;
    private ?string $jwksUri;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheItemPoolInterface $cache,
        ?string $issuer,
        ?string $authorizationEndpoint,
        ?string $tokenEndpoint,
        ?string $userinfoEndpoint,
        ?string $jwksUri,
    ) {
        $this->issuer = self::normalise($issuer);
        $this->authorizationEndpoint = self::normalise($authorizationEndpoint);
        $this->tokenEndpoint = self::normalise($tokenEndpoint);
        $this->userinfoEndpoint = self::normalise($userinfoEndpoint);
        $this->jwksUri = self::normalise($jwksUri);
    }

    public function isConfigured(): bool
    {
        return null !== $this->issuer;
    }

    public function resolve(): OidcMetadata
    {
        if (null === $this->issuer) {
            throw new OidcConfigurationException('OIDC login is not configured: OAUTH_OIDC_ISSUER is empty.');
        }

        $discovered = $this->needsDiscovery() ? $this->discover($this->issuer) : [];

        $authorization = $this->authorizationEndpoint ?? self::stringOrNull($discovered['authorization_endpoint'] ?? null);
        $token = $this->tokenEndpoint ?? self::stringOrNull($discovered['token_endpoint'] ?? null);
        $userinfo = $this->userinfoEndpoint ?? self::stringOrNull($discovered['userinfo_endpoint'] ?? null);
        $jwks = $this->jwksUri ?? self::stringOrNull($discovered['jwks_uri'] ?? null);

        $missing = [];

        foreach ([
            'OAUTH_OIDC_AUTHORIZE_URL' => $authorization,
            'OAUTH_OIDC_TOKEN_URL' => $token,
            'OAUTH_OIDC_USERINFO_URL' => $userinfo,
            'OAUTH_OIDC_JWKS_URL' => $jwks,
        ] as $name => $value) {
            if (null === $value) {
                $missing[] = $name;
            }
        }

        if ($missing) {
            throw new OidcConfigurationException(\sprintf('OIDC login is not usable: %s could not be discovered from %s and %s not set.', implode(', ', $missing), $this->discoveryUrl($this->issuer), 1 === \count($missing) ? 'is' : 'are'));
        }

        return new OidcMetadata($this->issuer, $authorization, $token, $userinfo, $jwks);
    }

    private function needsDiscovery(): bool
    {
        return null === $this->authorizationEndpoint
            || null === $this->tokenEndpoint
            || null === $this->userinfoEndpoint
            || null === $this->jwksUri;
    }

    /**
     * @return array<string, mixed>
     */
    private function discover(string $issuer): array
    {
        $item = $this->cache->getItem('oidc.metadata.'.hash('sha256', $issuer));

        if ($item->isHit()) {
            /** @var array<string, mixed> $cached */
            $cached = $item->get();

            return $cached;
        }

        $url = $this->discoveryUrl($issuer);

        try {
            $document = $this->httpClient->request('GET', $url)->toArray();
        } catch (\Throwable $e) {
            throw new OidcConfigurationException(\sprintf('OIDC discovery failed at %s: %s', $url, $e->getMessage()), 0, $e);
        }

        // OIDC Discovery 1.0 section 4.3: the issuer in the document MUST be
        // identical to the one the document was fetched for. A document that
        // says otherwise is either misconfigured or not the provider's own.
        if (($document['issuer'] ?? null) !== $issuer) {
            throw new OidcConfigurationException(\sprintf('OIDC discovery at %s returned issuer %s, which is not the configured issuer %s.', $url, var_export($document['issuer'] ?? null, true), $issuer));
        }

        $item->set($document);
        $item->expiresAfter(self::CACHE_TTL);
        $this->cache->save($item);

        return $document;
    }

    private function discoveryUrl(string $issuer): string
    {
        return rtrim($issuer, '/').'/.well-known/openid-configuration';
    }

    private static function normalise(?string $value): ?string
    {
        $value = null === $value ? '' : trim($value);

        return '' === $value ? null : $value;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }
}
