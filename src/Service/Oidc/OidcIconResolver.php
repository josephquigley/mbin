<?php

declare(strict_types=1);

namespace App\Service\Oidc;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches the provider's favicon so the login button can carry its mark.
 *
 * OpenID Connect has nowhere to publish this. Neither the discovery document
 * (OIDC Discovery 1.0, RFC 8414) nor the userinfo response carries a name or a
 * logo for the provider: `logo_uri` and `client_name` are *client* metadata,
 * which travels the other way. So the icon is guessed at the one location
 * every web server puts it, and the guess is allowed to fail.
 *
 * The result is a data URI rather than a link to the provider, deliberately:
 *
 *  * The login page then makes no third-party request, so rendering it does
 *    not tell the provider who is about to sign in.
 *  * It works when the provider is reachable from this container but not from
 *    the member's browser, which is the normal case for an IdP on a private
 *    network.
 *
 * Every failure returns null and the caller falls back to a generic icon. A
 * provider that serves no favicon, serves something enormous, serves HTML, or
 * cannot be reached must never affect whether people can log in.
 */
class OidcIconResolver
{
    private const CACHE_TTL = 86400;
    private const FAILURE_CACHE_TTL = 3600;
    private const MAX_BYTES = 32768;
    private const TIMEOUT_SECONDS = 2;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheItemPoolInterface $cache,
        private readonly ?string $issuer,
        private readonly ?string $fetchIcon,
    ) {
    }

    public function isEnabled(): bool
    {
        // Default on. Only an explicit false, 0, off or no turns it off, so an
        // instance that upgrades without setting the variable keeps working.
        return !\in_array(strtolower(trim((string) $this->fetchIcon)), ['false', '0', 'off', 'no'], true);
    }

    /**
     * @return string|null a data: URI, or null when there is nothing usable
     */
    public function resolve(): ?string
    {
        if (!$this->isEnabled() || null === $this->issuer || '' === trim($this->issuer)) {
            return null;
        }

        $item = $this->cache->getItem('oidc.icon.'.hash('sha256', $this->issuer));

        if ($item->isHit()) {
            $cached = $item->get();

            return \is_string($cached) && '' !== $cached ? $cached : null;
        }

        $icon = $this->fetch(rtrim(trim($this->issuer), '/').'/favicon.ico');

        $item->set($icon ?? '');
        $item->expiresAfter(null === $icon ? self::FAILURE_CACHE_TTL : self::CACHE_TTL);
        $this->cache->save($item);

        return $icon;
    }

    private function fetch(string $url): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
            ]);

            if (200 !== $response->getStatusCode()) {
                return null;
            }

            $contentType = $response->getHeaders(false)['content-type'][0] ?? '';

            if (!str_starts_with($contentType, 'image/')) {
                return null;
            }

            $body = $response->getContent(false);
        } catch (\Throwable) {
            return null;
        }

        if ('' === $body || \strlen($body) > self::MAX_BYTES) {
            return null;
        }

        $mime = trim(explode(';', $contentType)[0]);

        return 'data:'.$mime.';base64,'.base64_encode($body);
    }
}
