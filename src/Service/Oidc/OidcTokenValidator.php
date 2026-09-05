<?php

declare(strict_types=1);

namespace App\Service\Oidc;

use App\Service\Oidc\Exception\OidcValidationException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Verifies an OIDC id_token.
 *
 * JWT::decode() covers the signature, the algorithm and the expiry. Everything
 * else here is OpenID Connect rather than JWT: the issuer must be the one that
 * was configured, the audience must include this client, and the nonce must be
 * the value this instance sent with the authorization request. Without the
 * nonce check a token minted for another session would be accepted.
 */
class OidcTokenValidator
{
    private const LEEWAY = 60;

    /**
     * @param \Closure(): (array<string, Key>|\ArrayAccess<string, Key>) $keySetResolver
     *                                                                                   deferred, so that a misconfigured instance still boots and only
     *                                                                                   an OIDC login fails
     */
    public function __construct(
        private readonly \Closure $keySetResolver,
        private readonly string $issuer,
        private readonly string $clientId,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function validate(string $idToken, string $expectedNonce): array
    {
        $previousLeeway = JWT::$leeway;
        JWT::$leeway = self::LEEWAY;

        try {
            $claims = (array) JWT::decode($idToken, ($this->keySetResolver)());
        } catch (\Throwable $e) {
            throw new OidcValidationException('The id_token could not be verified: '.$e->getMessage(), 0, $e);
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        $this->assertIssuer($claims);
        $this->assertAudience($claims);
        $this->assertIssuedAt($claims);
        $this->assertExpiry($claims);
        $this->assertNonce($claims, $expectedNonce);
        $this->assertSubject($claims);

        return $claims;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function assertIssuer(array $claims): void
    {
        if (($claims['iss'] ?? null) !== $this->issuer) {
            throw new OidcValidationException('The id_token issuer does not match the configured issuer.');
        }
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function assertAudience(array $claims): void
    {
        $audience = $claims['aud'] ?? null;
        $audiences = \is_array($audience) ? $audience : [$audience];

        if (!\in_array($this->clientId, $audiences, true)) {
            throw new OidcValidationException('The id_token audience does not contain this client.');
        }

        if (\count($audiences) > 1 && ($claims['azp'] ?? null) !== $this->clientId) {
            throw new OidcValidationException('The id_token has more than one audience and its azp claim is not this client.');
        }
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function assertIssuedAt(array $claims): void
    {
        $issuedAt = $claims['iat'] ?? null;

        if (!\is_int($issuedAt) || $issuedAt > time() + self::LEEWAY) {
            throw new OidcValidationException('The id_token iat claim is missing or in the future.');
        }
    }

    /**
     * JWT::decode() only checks exp when the token carries one. OpenID Connect
     * makes the claim mandatory, and without it a token would be valid for
     * ever.
     *
     * @param array<string, mixed> $claims
     */
    private function assertExpiry(array $claims): void
    {
        if (!\is_int($claims['exp'] ?? null)) {
            throw new OidcValidationException('The id_token exp claim is missing.');
        }
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function assertNonce(array $claims, string $expectedNonce): void
    {
        // A session that never sent a nonce cannot have an id_token to check.
        // Refusing here keeps an empty expected value from matching a token
        // that simply omits the claim.
        if ('' === $expectedNonce) {
            throw new OidcValidationException('No nonce was sent with the authorization request.');
        }

        if (!hash_equals($expectedNonce, (string) ($claims['nonce'] ?? ''))) {
            throw new OidcValidationException('The id_token nonce does not match the value sent with the request.');
        }
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function assertSubject(array $claims): void
    {
        if (!isset($claims['sub']) || !\is_string($claims['sub']) || '' === $claims['sub']) {
            throw new OidcValidationException('The id_token has no usable sub claim.');
        }
    }
}
