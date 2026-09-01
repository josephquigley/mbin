<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber\ActivityPub;

use App\EventSubscriber\ActivityPub\AuthorizedFetchSubscriber;
use App\Exception\InvalidApSignatureException;
use App\Service\ActivityPub\SignatureValidator;
use App\Service\SettingsManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * A refusal must be logged at a level production actually records.
 *
 * config/packages/monolog.yaml, when@prod, wraps everything in fingers_crossed
 * with action_level: error over handlers set to level: warning. An info line is
 * therefore discarded unless some unrelated error trips the buffer in the same
 * request — and a refusal returns 401 cleanly, which is not an error. Logged at
 * info, a refusal is invisible in production and the admin sees a bare 401 with
 * no reason, which is the hardest kind of federation problem to diagnose.
 */
class AuthorizedFetchSubscriberLoggingTest extends TestCase
{
    private const SIGNATURE = 'keyId="https://remote.example/u/alice#main-key",algorithm="rsa-sha256",headers="(request-target) host date",signature="Zm9v"';

    private function event(): RequestEvent
    {
        $request = Request::create('https://local.example/u/bob', 'GET');
        $request->headers->set('signature', self::SIGNATURE);
        $request->attributes->set('_route', 'ap_user');

        return new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    private function settings(bool $banned): SettingsManager
    {
        $settings = $this->createMock(SettingsManager::class);
        $settings->method('isAuthorizedFetchEnabled')->willReturn(true);
        $settings->method('isBannedInstance')->willReturn($banned);

        return $settings;
    }

    /**
     * The allow-list refusal: the case an admin hits after enabling the feature
     * and forgetting to add a peer.
     */
    public function testAnAllowListRefusalIsLoggedAtWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('info');
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('non-federated instance'));

        $subscriber = new AuthorizedFetchSubscriber(
            $this->settings(banned: true),
            $this->createMock(SignatureValidator::class),
            $logger,
        );

        $event = $this->event();
        $subscriber->onKernelRequest($event);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $event->getResponse()?->getStatusCode());
    }

    /**
     * The signature refusal: the other case that returns 401 with no body worth
     * reading, so the log is the only place the reason can appear.
     */
    public function testAnUnverifiableSignatureIsLoggedAtWarning(): void
    {
        $validator = $this->createMock(SignatureValidator::class);
        $validator->method('validateGetRequest')
            ->willThrowException(new InvalidApSignatureException('Signature of GET request could not be verified.'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('info');
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Refusing unverified'));

        $subscriber = new AuthorizedFetchSubscriber($this->settings(banned: false), $validator, $logger);

        $event = $this->event();
        $subscriber->onKernelRequest($event);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $event->getResponse()?->getStatusCode());
    }

    /**
     * The success path stays quiet. A per-request line at warning on every served
     * object would be worse than the problem this fixes.
     */
    public function testServingAVerifiedActorDoesNotLogAtWarning(): void
    {
        $validator = $this->createMock(SignatureValidator::class);
        $validator->method('validateGetRequest')->willReturn('https://remote.example/u/alice');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $subscriber = new AuthorizedFetchSubscriber($this->settings(banned: false), $validator, $logger);

        $event = $this->event();
        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }
}
