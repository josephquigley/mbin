<?php

declare(strict_types=1);

namespace App\Controller\Security;

use App\Controller\AbstractController;
use App\Security\Oidc\OidcClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class OidcController extends AbstractController
{
    public function connect(OidcClient $client): Response
    {
        // No scope list here on purpose: App\Provider\Oidc decides, because
        // whether the groups scope is needed depends on configuration this
        // controller has no business reading.
        return $client->redirect();
    }

    public function verify(Request $request, OidcClient $client): void
    {
    }
}
