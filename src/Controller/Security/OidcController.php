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
        return $client->redirect([
            'openid',
            'email',
            'profile',
        ]);
    }

    public function verify(Request $request, OidcClient $client): void
    {
    }
}
