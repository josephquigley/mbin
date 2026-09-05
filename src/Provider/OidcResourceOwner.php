<?php

declare(strict_types=1);

namespace App\Provider;

use League\OAuth2\Client\Provider\ResourceOwnerInterface;

class OidcResourceOwner implements ResourceOwnerInterface
{
    /**
     * @param array<string, mixed> $response
     */
    public function __construct(
        private readonly array $response,
        private readonly string $usernameClaim,
    ) {
    }

    public function getId(): ?string
    {
        return $this->claim('sub');
    }

    public function getEmail(): ?string
    {
        return $this->claim('email');
    }

    /**
     * Providers disagree about which claim carries a human readable name, so
     * the claim is configurable. The subject identifier is the fallback: it is
     * the one claim OpenID Connect guarantees.
     */
    public function getUsername(): ?string
    {
        return $this->claim($this->usernameClaim) ?? $this->claim('sub');
    }

    /**
     * Whether the provider vouches for the email address. OpenID Connect
     * defines the claim as a boolean, but some providers serialise it as the
     * string "true". Anything else, including an absent claim, is unverified.
     */
    public function isEmailVerified(): bool
    {
        $value = $this->response['email_verified'] ?? null;

        return true === $value || 'true' === $value;
    }

    public function getPictureUrl(): ?string
    {
        return $this->claim('picture');
    }

    /**
     * Group names from the userinfo response, or an empty list when the
     * provider sends none. Only used when OAUTH_OIDC_ADMIN_GROUP is set.
     *
     * @return string[]
     */
    public function getGroups(): array
    {
        $groups = $this->response['groups'] ?? null;

        if (!\is_array($groups)) {
            return [];
        }

        return array_values(array_filter($groups, 'is_string'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->response;
    }

    private function claim(string $name): ?string
    {
        $value = $this->response[$name] ?? null;

        return \is_scalar($value) && '' !== (string) $value ? (string) $value : null;
    }
}
