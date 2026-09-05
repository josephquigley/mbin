<?php

declare(strict_types=1);

namespace App\Security\Oidc;

use KnpU\OAuth2ClientBundle\Client\OAuth2PKCEClient;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * The OAuth2 client for the generic OIDC provider.
 *
 * OAuth2PKCEClient already carries the code verifier across the two requests
 * of the flow, so PKCE needs nothing here. What the bundle knows nothing about
 * is the nonce, which OpenID Connect requires and which is the only thing
 * binding an id_token to the session that asked for it.
 */
class OidcClient extends OAuth2PKCEClient
{
    public const NONCE_KEY = 'oidc_nonce';

    /**
     * @param string[]             $scopes
     * @param array<string, mixed> $options
     */
    public function redirect(array $scopes = [], array $options = []): RedirectResponse
    {
        $nonce = bin2hex(random_bytes(16));
        $this->getSession()->set(self::NONCE_KEY, $nonce);

        return parent::redirect($scopes, $options + ['nonce' => $nonce]);
    }

    /**
     * Returns the nonce sent with the authorization request and forgets it, so
     * that a replayed callback cannot be validated against it a second time.
     */
    public function consumeNonce(): string
    {
        $session = $this->getSession();
        $nonce = (string) $session->get(self::NONCE_KEY, '');
        $session->remove(self::NONCE_KEY);

        return $nonce;
    }
}
