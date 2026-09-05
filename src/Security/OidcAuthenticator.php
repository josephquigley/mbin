<?php

declare(strict_types=1);

namespace App\Security;

use App\DTO\UserDto;
use App\Entity\Image;
use App\Entity\User;
use App\Factory\ImageFactory;
use App\Provider\OidcResourceOwner;
use App\Repository\ImageRepository;
use App\Repository\UserRepository;
use App\Security\Oidc\OidcClient;
use App\Service\ImageManagerInterface;
use App\Service\IpResolver;
use App\Service\Oidc\Exception\OidcValidationException;
use App\Service\Oidc\OidcTokenValidator;
use App\Service\SettingsManager;
use App\Service\UserManager;
use App\Utils\Slugger;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Client\Token\AccessToken;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class OidcAuthenticator extends MbinOAuthAuthenticatorBase
{
    /**
     * The message shown when a login is refused. It is deliberately vague: the
     * reason goes to the log, where an administrator can read it, and not to
     * the browser, where it would tell whoever is trying which check they
     * failed. MbinOAuthAuthenticatorBase renders this string directly rather
     * than translating it.
     */
    private const FAILURE_MESSAGE = 'Authentication failed.';

    public function __construct(
        private readonly OidcClient $client,
        private readonly OidcTokenValidator $tokenValidator,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserManager $userManager,
        private readonly ImageManagerInterface $imageManager,
        private readonly ImageFactory $imageFactory,
        private readonly ImageRepository $imageRepository,
        private readonly IpResolver $ipResolver,
        private readonly Slugger $slugger,
        private readonly UserRepository $userRepository,
        private readonly SettingsManager $settingsManager,
        private readonly LoggerInterface $logger,
        RouterInterface $router,
    ) {
        parent::__construct($router);
    }

    public function supports(Request $request): ?bool
    {
        return 'oauth_oidc_verify' === $request->attributes->get('_route');
    }

    public function authenticate(Request $request): Passport
    {
        $expectedNonce = $this->client->consumeNonce();
        $accessToken = $this->client->getAccessToken();

        if (!$accessToken instanceof AccessToken) {
            $this->logger->error('OIDC login failed: the token endpoint returned an unusable token');

            throw new CustomUserMessageAuthenticationException(self::FAILURE_MESSAGE);
        }

        $idToken = $accessToken->getValues()['id_token'] ?? null;

        if (!\is_string($idToken) || '' === $idToken) {
            $this->logger->error('OIDC login failed: the token response carried no id_token');

            throw new CustomUserMessageAuthenticationException(self::FAILURE_MESSAGE);
        }

        try {
            $claims = $this->tokenValidator->validate($idToken, $expectedNonce);
        } catch (OidcValidationException $e) {
            $this->logger->warning('OIDC login failed: {reason}', ['reason' => $e->getMessage()]);

            throw new CustomUserMessageAuthenticationException(self::FAILURE_MESSAGE);
        }

        $rememberBadge = (new RememberMeBadge())->enable();

        return new SelfValidatingPassport(
            new UserBadge($accessToken->getToken(), function () use ($accessToken, $claims, $request) {
                /** @var OidcResourceOwner $oidcUser */
                $oidcUser = $this->client->fetchUserFromToken($accessToken);

                // The userinfo response and the id_token must describe the same
                // person. Without this, a token obtained for one subject could
                // be paired with another subject's profile.
                if (!hash_equals((string) $claims['sub'], (string) $oidcUser->getId())) {
                    $this->logger->error('OIDC login failed: the userinfo sub does not match the id_token sub');

                    throw new CustomUserMessageAuthenticationException(self::FAILURE_MESSAGE);
                }

                $existingUser = $this->entityManager->getRepository(User::class)->findOneBy(
                    ['oauthOidcId' => $oidcUser->getId()]
                );

                if ($existingUser) {
                    return $existingUser;
                }

                $email = $oidcUser->getEmail();

                if (null === $email) {
                    $this->logger->error('OIDC login failed: the provider returned no email claim');

                    throw new CustomUserMessageAuthenticationException(self::FAILURE_MESSAGE);
                }

                $user = $this->userRepository->findOneBy(['email' => $email]);

                if ($user) {
                    // Matching on email hands over an existing account, so
                    // the provider must vouch for the address. Otherwise
                    // anyone able to register an unverified address at the
                    // provider could sign in as whichever member owns it.
                    if (!$oidcUser->isEmailVerified()) {
                        $this->logger->error('OIDC login refused: the email matches an existing account but the provider did not mark it verified');

                        throw new CustomUserMessageAuthenticationException(self::FAILURE_MESSAGE);
                    }

                    $user->oauthOidcId = $oidcUser->getId();

                    $this->entityManager->persist($user);
                    $this->entityManager->flush();

                    return $user;
                }

                if (false === $this->settingsManager->get('MBIN_SSO_REGISTRATIONS_ENABLED')) {
                    throw new CustomUserMessageAuthenticationException('MBIN_SSO_REGISTRATIONS_ENABLED');
                }

                $username = $this->slugger->slug((string) $oidcUser->getUsername());

                if ($this->userRepository->count(['username' => $username]) > 0) {
                    $username .= rand(1, 999);
                    $request->getSession()->set('is_newly_created', true);
                }

                $dto = (new UserDto())->create($username, $email);
                $dto->plainPassword = bin2hex(random_bytes(20));
                $dto->ip = $this->ipResolver->resolve();

                $avatar = $this->getAvatar($oidcUser->getPictureUrl());

                if ($avatar) {
                    $dto->avatar = $this->imageFactory->createDto($avatar);
                }

                $user = $this->userManager->create($dto, false);
                $user->oauthOidcId = $oidcUser->getId();
                $user->avatar = $avatar;
                $user->isVerified = true;

                $this->entityManager->persist($user);
                $this->entityManager->flush();

                return $user;
            }),
            [
                $rememberBadge,
            ]
        );
    }

    private function getAvatar(?string $pictureUrl): ?Image
    {
        if (!$pictureUrl) {
            return null;
        }

        try {
            $tempFile = $this->imageManager->download($pictureUrl);
        } catch (\Exception) {
            return null;
        }

        if (!$tempFile) {
            return null;
        }

        $image = $this->imageRepository->findOrCreateFromPath($tempFile);

        if ($image) {
            $this->entityManager->persist($image);
            $this->entityManager->flush();
        }

        return $image;
    }
}
