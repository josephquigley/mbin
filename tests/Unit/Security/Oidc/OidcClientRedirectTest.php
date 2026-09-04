<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\Oidc;

use App\Security\Oidc\OidcClient;
use League\OAuth2\Client\Provider\GenericProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

class OidcClientRedirectTest extends TestCase
{
    public function testRedirectCarriesPkceChallengeAndNonce(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $response = $this->client($session)->redirect(['openid']);

        parse_str((string) parse_url($response->getTargetUrl(), PHP_URL_QUERY), $query);

        self::assertSame('S256', $query['code_challenge_method'] ?? null);
        self::assertNotEmpty($query['code_challenge'] ?? '');
        self::assertNotEmpty($query['nonce'] ?? '');
        self::assertSame($query['nonce'], $session->get(OidcClient::NONCE_KEY));
        self::assertNotEmpty($session->get('pkce_code_verifier'));
    }

    public function testConsumeNonceIsSingleUse(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set(OidcClient::NONCE_KEY, 'abc');

        $client = $this->client($session);

        self::assertSame('abc', $client->consumeNonce());
        self::assertSame('', $client->consumeNonce());
    }

    private function client(SessionInterface $session): OidcClient
    {
        $request = new Request();
        $request->setSession($session);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        // GenericProvider stands in for App\Provider\Oidc: this test is about
        // the client's session handling, not about endpoint resolution.
        $provider = new GenericProvider([
            'clientId' => 'mbin',
            'clientSecret' => 'secret',
            'redirectUri' => 'https://mbin.test/oauth/oidc/verify',
            'urlAuthorize' => 'https://idp.test/authorize',
            'urlAccessToken' => 'https://idp.test/token',
            'urlResourceOwnerDetails' => 'https://idp.test/userinfo',
        ]);

        return new OidcClient($provider, $requestStack);
    }
}
