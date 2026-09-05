<?php

declare(strict_types=1);

namespace App\EventSubscriber\ActivityPub;

use App\Exception\InboxForwardingException;
use App\Service\ActivityPub\SignatureValidator;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Verifies the HTTP signature on an inbound ActivityPub POST before the request is
 * dispatched onto the message bus.
 *
 * The inbox controllers accept anything: each one logs the request, dispatches an
 * ActivityMessage and answers 200, and the signature is checked later, in
 * ActivityHandler. That leaves two steps that run on an unauthenticated payload.
 * ActivityHandler reads the payload's own "id" field, creates an Instance row for
 * whatever host it names, and calls RemoteInstanceManager::updateInstance(), which
 * fetches that host's nodeinfo and then follows the link it finds there. Both happen
 * before verifyInstanceDomain() and findActorOrCreate() get a chance to discard the
 * message. So an unauthenticated caller can write rows into the instance table and
 * make this instance issue outbound requests to a host of their choosing.
 *
 * Checking the signature here closes that, and it costs what it costs: verifying a
 * signature means resolving the sending actor, which for an actor this instance has
 * not seen before is an outbound fetch inside the request. That is the price of not
 * acting on unauthenticated input, and it is paid once per actor rather than once
 * per activity, since the actor is stored.
 */
class InboxSignatureSubscriber implements EventSubscriberInterface
{
    /**
     * Runs after RouterListener (priority 32), so the route is resolved, and after the
     * firewall (priority 8), so this never pre-empts an authentication error.
     */
    public const int PRIORITY = 7;

    /**
     * The inbox routes, which are the POST endpoints that dispatch to the bus.
     *
     * A path list would drift from the routing file. These names come from
     * config/mbin_routes/activity_pub.yaml and are the whole set of routes whose
     * controller is an inbox controller.
     */
    public const array INBOX_ROUTES = [
        'ap_instance_inbox',
        'ap_shared_inbox',
        'ap_user_inbox',
        'ap_magazine_inbox',
    ];

    public function __construct(
        private readonly SignatureValidator $signatureValidator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', self::PRIORITY],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!\in_array($request->attributes->get('_route'), self::INBOX_ROUTES, true)) {
            return;
        }

        // Checked here rather than left to the validator, which reads both headers
        // positionally and raises "Undefined array key" before it gets to the check
        // that would have refused the request anyway.
        if (null === $request->headers->get('signature') || null === $request->headers->get('date')) {
            $this->logger->warning('[InboxSignatureSubscriber] Refusing an inbound activity with no signature or date header');
            $event->setResponse($this->refuse());

            return;
        }

        try {
            $this->signatureValidator->validate(
                ['uri' => $request->getRequestUri()],
                $request->headers->all(),
                $request->getContent(),
            );
        } catch (InboxForwardingException $e) {
            // Not a failure. The activity was relayed by an instance other than the
            // one that produced it, which is what inbox forwarding is, and the
            // consumer handles it by fetching the activity from its real origin
            // rather than trusting the copy it was handed. Let it through to the
            // bus, which is where that handling lives.
            $this->logger->debug('[InboxSignatureSubscriber] Accepting a forwarded activity from {receivedFrom}, originating at {realOrigin}', [
                'receivedFrom' => $e->receivedFrom,
                'realOrigin' => $e->realOrigin,
            ]);

            return;
        } catch (\Exception $e) {
            // Warning rather than info: the production Monolog stack buffers below
            // error level, so an info line here would be discarded unless some
            // unrelated error tripped the buffer in the same request, and the admin
            // would see a bare 401 with nothing explaining it.
            $this->logger->warning('[InboxSignatureSubscriber] Refusing an inbound activity that could not be authenticated: {reason}', [
                'reason' => $e->getMessage(),
            ]);

            $event->setResponse($this->refuse());
        }
    }

    /**
     * One refusal for every cause. A response that varies by cause tells a caller
     * which part of their request was wrong, which is not something an unauthenticated
     * caller needs to know.
     */
    private function refuse(): JsonResponse
    {
        return new JsonResponse(
            ['error' => 'This instance requires a valid HTTP signature on ActivityPub POST requests.'],
            Response::HTTP_UNAUTHORIZED,
            ['Content-Type' => 'application/activity+json'],
        );
    }
}
