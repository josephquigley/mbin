<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\OidcAuthenticator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Guards the isolation rule: adding an OIDC provider must not change what
 * happens on any other provider's callback. If this fails, the new
 * authenticator has started claiming requests that belong to someone else.
 */
class OidcAuthenticatorSupportsTest extends TestCase
{
    #[DataProvider('otherAuthenticationRoutes')]
    public function testDoesNotClaimAnotherProvidersCallback(string $route): void
    {
        self::assertFalse($this->authenticator()->supports($this->requestForRoute($route)));
    }

    public function testClaimsItsOwnCallback(): void
    {
        self::assertTrue($this->authenticator()->supports($this->requestForRoute('oauth_oidc_verify')));
    }

    /**
     * @return \Generator<array{string}>
     */
    public static function otherAuthenticationRoutes(): \Generator
    {
        yield ['oauth_azure_verify'];
        yield ['oauth_facebook_verify'];
        yield ['oauth_google_verify'];
        yield ['oauth_discord_verify'];
        yield ['oauth_github_verify'];
        yield ['oauth_privacyportal_verify'];
        yield ['oauth_keycloak_verify'];
        yield ['oauth_simplelogin_verify'];
        yield ['oauth_zitadel_verify'];
        yield ['oauth_authentik_verify'];
        yield ['oauth_oidc_connect'];
        yield ['app_login'];
    }

    private function requestForRoute(string $route): Request
    {
        $request = new Request();
        $request->attributes->set('_route', $route);

        return $request;
    }

    private function authenticator(): OidcAuthenticator
    {
        // supports() reads only the request, so the collaborators are
        // irrelevant here. Building without the constructor keeps this test
        // from being rewritten every time the dependency list changes.
        return (new \ReflectionClass(OidcAuthenticator::class))->newInstanceWithoutConstructor();
    }
}
