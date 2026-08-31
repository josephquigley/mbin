<?php

declare(strict_types=1);

namespace App\EventSubscriber\ActivityPub;

use App\Service\ActivityPub\SignatureValidator;
use App\Service\SettingsManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Authorized fetch: requires a valid HTTP signature on inbound ActivityPub GET requests.
 *
 * This is off unless MBIN_AUTHORIZED_FETCH is enabled, in which case it refuses to serve
 * the ActivityPub representation of anything to a peer that does not sign its GET
 * requests. It authenticates the sender of a request. It does not encrypt anything, and
 * it is only as good as the retrieval of the sender's public key that backs it.
 *
 * The gate keys on the resolved route name rather than on a path list. Mbin's ActivityPub
 * content routes carry an Accept-header condition (kbin_ap_route_condition), so by the
 * time this listener runs the router has already decided whether the request is an
 * ActivityPub one: if it is not, the matched route is the HTML route and its name does
 * not start with "ap_". Keying on the route name therefore inherits that condition
 * exactly, and cannot drift from it.
 *
 * An ap_* GET route that nobody has excluded is gated. That is deliberate: a route added
 * later fails closed rather than open.
 */
class AuthorizedFetchSubscriber implements EventSubscriberInterface
{
    /**
     * Runs after RouterListener (priority 32), so the route is resolved, and after the
     * firewall (priority 8), so this never pre-empts an authentication error.
     */
    public const int PRIORITY = 7;

    /**
     * Routes that stay readable without a signature even when authorized fetch is on.
     *
     * ap_instance is the instance actor, which carries the public key a peer needs in
     * order to verify anything we send it. Gating it would be a bootstrapping deadlock:
     * a peer could never make its first signed request to us. The rest are discovery
     * documents that carry no user content and that a peer needs before it knows our
     * actor URLs at all.
     */
    public const array UNGATED_ROUTES = [
        'ap_instance',
        'ap_webfinger',
        'ap_hostmeta',
        'ap_node_info',
        'ap_node_info_v2',
        'ap_contexts',
    ];

    public function __construct(
        private readonly SettingsManager $settingsManager,
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
        if (!$event->isMainRequest() || !$this->settingsManager->isAuthorizedFetchEnabled()) {
            return;
        }

        $request = $event->getRequest();

        if (!$request->isMethod('GET')) {
            return;
        }

        $route = $request->attributes->get('_route');
        if (!\is_string($route) || !str_starts_with($route, 'ap_') || \in_array($route, self::UNGATED_ROUTES, true)) {
            return;
        }

        try {
            $actorUrl = $this->signatureValidator->validateGetRequest($request->getRequestUri(), $request->headers->all());
        } catch (\Exception $e) {
            $this->logger->info('[AuthorizedFetchSubscriber] Refusing unverified ActivityPub GET of {route}: {reason}', [
                'route' => $route,
                'reason' => $e->getMessage(),
            ]);

            $event->setResponse($this->refuse('This instance requires a valid HTTP signature on ActivityPub requests.'));

            return;
        }

        $this->logger->debug('[AuthorizedFetchSubscriber] Serving {route} to verified actor {actor}', [
            'route' => $route,
            'actor' => $actorUrl,
        ]);
    }

    private function refuse(string $reason): Response
    {
        return new Response($reason, Response::HTTP_UNAUTHORIZED, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'WWW-Authenticate' => 'Signature realm="ActivityPub"',
        ]);
    }
}
