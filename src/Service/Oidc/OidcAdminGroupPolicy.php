<?php

declare(strict_types=1);

namespace App\Service\Oidc;

use App\Provider\OidcResourceOwner;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Decides whether a login should carry admin rights, from a group claim.
 *
 * Disabled unless OAUTH_OIDC_ADMIN_GROUP names a group. That default is the
 * important one: an instance that does not configure this keeps granting admin
 * the only way Mbin has ever granted it, with `bin/console mbin:user:admin`,
 * and no claim from any provider can change who its administrators are.
 *
 * Promotion only. Losing the group in the provider does not remove an existing
 * Mbin admin, and that asymmetry is deliberate rather than unfinished: on an
 * instance with MBIN_SSO_ONLY_MODE set there is no password login to fall back
 * on, so a provider that stops emitting the claim (a renamed group, a bad
 * migration, a scope that silently stopped being granted) would lock every
 * administrator out of their own instance at once. Removing admin is therefore
 * left as a local, deliberate act.
 *
 * The claim is read from the id_token first, which has been verified, and only
 * then from the userinfo response. The userinfo response is not signed, so it
 * is only as trustworthy as the transport it arrived over: it is consulted
 * only when the userinfo endpoint is HTTPS. An admin who overrides the
 * endpoint with a plain http:// address on a container network keeps working
 * logins, but that response cannot appoint administrators.
 */
class OidcAdminGroupPolicy
{
    private ?string $adminGroup;
    private LoggerInterface $logger;

    public function __construct(
        ?string $adminGroup,
        private readonly OidcMetadataResolver $metadataResolver,
        ?LoggerInterface $logger = null,
    ) {
        $adminGroup = trim((string) $adminGroup);
        $this->adminGroup = '' === $adminGroup ? null : $adminGroup;
        $this->logger = $logger ?? new NullLogger();
    }

    public function isEnabled(): bool
    {
        return null !== $this->adminGroup;
    }

    public function group(): ?string
    {
        return $this->adminGroup;
    }

    /**
     * @param array<string, mixed> $idTokenClaims
     */
    public function shouldPromote(array $idTokenClaims, OidcResourceOwner $resourceOwner): bool
    {
        if (null === $this->adminGroup) {
            return false;
        }

        $groups = self::groupsIn($idTokenClaims);

        if (null === $groups) {
            if (!$this->userinfoIsTrusted()) {
                $this->logger->warning('OIDC admin group ignored: the id_token carries no groups claim and the userinfo endpoint is not HTTPS');

                return false;
            }

            $groups = $resourceOwner->getGroups();
        }

        return \in_array($this->adminGroup, $groups, true);
    }

    private function userinfoIsTrusted(): bool
    {
        try {
            $endpoint = $this->metadataResolver->resolve()->userinfoEndpoint;
        } catch (\Throwable) {
            return false;
        }

        return str_starts_with(strtolower($endpoint), 'https://');
    }

    /**
     * @param array<string, mixed> $claims
     *
     * @return string[]|null null when the claim is absent or null, which is
     *                       different from present and empty: absent means
     *                       look further
     */
    private static function groupsIn(array $claims): ?array
    {
        $groups = $claims['groups'] ?? null;

        if (null === $groups) {
            return null;
        }

        if (!\is_array($groups)) {
            return [];
        }

        return array_values(array_filter($groups, 'is_string'));
    }
}
