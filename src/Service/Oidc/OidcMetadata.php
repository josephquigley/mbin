<?php

declare(strict_types=1);

namespace App\Service\Oidc;

class OidcMetadata
{
    public function __construct(
        public readonly string $issuer,
        public readonly string $authorizationEndpoint,
        public readonly string $tokenEndpoint,
        public readonly string $userinfoEndpoint,
        public readonly string $jwksUri,
    ) {
    }
}
