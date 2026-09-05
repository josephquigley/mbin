<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\ActivityPub;

use App\Tests\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Inbound ActivityPub POSTs must be authenticated before the request is dispatched
 * onto the message bus, because everything the consumer does with the payload
 * (creating an Instance row, fetching that host's nodeinfo) happens before the
 * consumer checks who sent it.
 */
class InboxSignatureTest extends WebTestCase
{
    public static function provideInboxPaths(): array
    {
        return [
            'instance inbox' => ['/i/inbox'],
            'shared inbox' => ['/f/inbox'],
            'user inbox' => ['/u/user/inbox'],
            'magazine inbox' => ['/m/acme/inbox'],
        ];
    }

    #[DataProvider('provideInboxPaths')]
    public function testInboxRefusesAnUnsignedPost(string $path): void
    {
        $this->client->request('POST', $path, [], [], [
            'CONTENT_TYPE' => 'application/activity+json',
        ], json_encode([
            'id' => 'https://example.com/activities/1',
            'type' => 'Create',
            'actor' => 'https://example.com/u/someone',
        ]));

        self::assertResponseStatusCodeSame(401);
    }

    #[DataProvider('provideInboxPaths')]
    public function testInboxRefusesAPostWhoseSignatureCannotBeVerified(string $path): void
    {
        $this->client->request('POST', $path, [], [], [
            'CONTENT_TYPE' => 'application/activity+json',
            'HTTP_DATE' => gmdate('D, d M Y H:i:s \G\M\T'),
            'HTTP_SIGNATURE' => 'keyId="https://example.com/u/someone#main-key",headers="(request-target) date host",algorithm="rsa-sha256",signature="bm90LWEtc2lnbmF0dXJl"',
        ], json_encode([
            'id' => 'https://example.com/activities/1',
            'type' => 'Create',
            'actor' => 'https://example.com/u/someone',
        ]));

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * A malformed body cannot be authenticated either, and must not reach the bus.
     * This is the shape that costs the most when it is let through: the consumer
     * reads $payload['id'] and fetches nodeinfo from whatever host it names.
     */
    #[DataProvider('provideInboxPaths')]
    public function testInboxRefusesAPostWithOnlyAnId(string $path): void
    {
        $this->client->request('POST', $path, [], [], [
            'CONTENT_TYPE' => 'application/activity+json',
        ], json_encode([
            'id' => 'https://attacker.example/activities/1',
        ]));

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * The gate is keyed on the inbox routes, so it must not answer for anything else.
     * A GET of an actor is a different route and a different concern.
     */
    public function testTheGateDoesNotTouchNonInboxRoutes(): void
    {
        $this->getUserByUsername('user');

        $this->client->request('GET', '/u/user', [], [], [
            'HTTP_ACCEPT' => 'application/activity+json',
        ]);

        self::assertResponseIsSuccessful();
    }
}
