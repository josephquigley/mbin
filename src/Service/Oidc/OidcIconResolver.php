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
 * which travels the other way. So the icon is looked for the way a browser
 * looks for one, and the search is allowed to fail.
 *
 * Two attempts, in the order a browser would make them:
 *
 *  1. `{issuer}/favicon.ico`.
 *  2. The `<link rel="icon">` of the issuer's own root document.
 *
 * The second attempt is not belt and braces. A single-page IdP commonly serves
 * its application shell for every unknown path, so `/favicon.ico` answers 200
 * with `text/html` and no icon, while the shell's own `<link rel="icon">`
 * points at the real one. Pocket ID does exactly this: `/favicon.ico` is the
 * SPA fallback and the icon is at `/api/application-images/favicon`.
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
    private const MAX_HTML_BYTES = 262144;
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

        $issuer = rtrim(trim($this->issuer), '/');
        $icon = $this->fetchImage($issuer.'/favicon.ico');

        if (null === $icon) {
            $declared = $this->declaredIconUrl($issuer);
            $icon = null !== $declared ? $this->fetchImage($declared) : null;
        }

        $item->set($icon ?? '');
        $item->expiresAfter(null === $icon ? self::FAILURE_CACHE_TTL : self::CACHE_TTL);
        $this->cache->save($item);

        return $icon;
    }

    /**
     * Reads the issuer's root document and returns the URL its
     * `<link rel="icon">` points at, resolved against the issuer and rejected
     * unless it stays on the issuer's own origin. A provider that points
     * somewhere else is not followed: the admin configured an issuer, not a
     * licence to fetch arbitrary URLs from inside this network.
     */
    private function declaredIconUrl(string $issuer): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $issuer.'/', $this->requestOptions(self::MAX_HTML_BYTES));

            if (200 !== $response->getStatusCode()) {
                return null;
            }

            $html = substr($response->getContent(false), 0, self::MAX_HTML_BYTES);
        } catch (\Throwable) {
            return null;
        }

        if (!preg_match_all('/<link\s[^>]*>/i', $html, $tags)) {
            return null;
        }

        foreach ($tags[0] as $tag) {
            if (!preg_match('/\srel\s*=\s*["\']?[^"\'>]*icon/i', $tag)) {
                continue;
            }

            if (!preg_match('/\shref\s*=\s*["\']([^"\']+)["\']/i', $tag, $href)) {
                continue;
            }

            $candidate = $this->absoluteUrl($issuer, trim($href[1]));

            if (null !== $candidate) {
                return $candidate;
            }
        }

        return null;
    }

    private function absoluteUrl(string $issuer, string $href): ?string
    {
        if ('' === $href || str_starts_with($href, 'data:')) {
            return null;
        }

        $url = str_starts_with($href, 'http://') || str_starts_with($href, 'https://')
            ? $href
            : $issuer.'/'.ltrim($href, '/');

        $issuerParts = parse_url($issuer);
        $urlParts = parse_url($url);

        if (!\is_array($issuerParts) || !\is_array($urlParts)) {
            return null;
        }

        $sameOrigin = ($issuerParts['scheme'] ?? null) === ($urlParts['scheme'] ?? null)
            && ($issuerParts['host'] ?? null) === ($urlParts['host'] ?? null)
            && ($issuerParts['port'] ?? null) === ($urlParts['port'] ?? null);

        return $sameOrigin ? $url : null;
    }

    /**
     * Redirects are not followed, so a provider (or whoever answers for it)
     * cannot bounce this fetch to an address the same-origin check never saw.
     * The download is abandoned as soon as it grows past the limit rather
     * than after the whole body has been read into memory.
     *
     * @return array<string, mixed>
     */
    private function requestOptions(int $maxBytes): array
    {
        return [
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => self::TIMEOUT_SECONDS,
            'max_redirects' => 0,
            'on_progress' => static function (int $downloaded, int $total) use ($maxBytes): void {
                if ($downloaded > $maxBytes || $total > $maxBytes) {
                    throw new \OverflowException(\sprintf('The response exceeds %d bytes.', $maxBytes));
                }
            },
        ];
    }

    private function fetchImage(string $url): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $url, $this->requestOptions(self::MAX_BYTES));

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
