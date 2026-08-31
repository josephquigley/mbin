<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\WebTestCase;

class WebfingerControllerTest extends WebTestCase
{
    public function testInstanceActor(): void
    {
        $domain = $this->settingsManager->get('KBIN_DOMAIN');
        $resource = "acct:$domain@$domain";
        $resourceUrlEncoded = urlencode($resource);
        $this->client->request('GET', "https://$domain/.well-known/webfinger?resource=$resourceUrlEncoded");
        self::assertResponseIsSuccessful();
        $jsonContent = self::getJsonResponse($this->client);
        self::assertResponseIsSuccessful();

        self::assertArrayHasKey('subject', $jsonContent);
        self::assertEquals($resource, $jsonContent['subject']);
        self::assertArrayHasKey('links', $jsonContent);
        self::assertNotEmpty($jsonContent['links']);
        $instanceActor = $jsonContent['links'][0];
        self::assertArrayKeysMatch(['rel', 'href', 'type'], $instanceActor);

        $this->client->request('GET', $instanceActor['href']);

        self::assertResponseIsSuccessful();
        $jsonContent = self::getJsonResponse($this->client);
        self::assertNotNull($jsonContent);
        $keys = ['id', 'type', 'preferredUsername', 'publicKey', 'name', 'manuallyApprovesFollowers'];
        foreach ($keys as $key) {
            self::assertArrayHasKey($key, $jsonContent);
        }
        self::assertEquals($instanceActor['href'], $jsonContent['id']);
        self::assertEquals('Application', $jsonContent['type']);
        self::assertEquals($domain, $jsonContent['preferredUsername']);
        self::assertTrue($jsonContent['manuallyApprovesFollowers']);
        self::assertNotEmpty($jsonContent['publicKey']);
    }

    public function testMagazineActor(): void
    {
        $magazine = $this->getMagazineByName('acme');
        $domain = $this->settingsManager->get('KBIN_DOMAIN');
        $resource = "acct:{$magazine->name}@$domain";
        $this->client->request('GET', "https://$domain/.well-known/webfinger?resource=".urlencode($resource));

        self::assertResponseIsSuccessful();
        $jsonContent = self::getJsonResponse($this->client);

        self::assertArrayHasKey('subject', $jsonContent);
        self::assertEquals($resource, $jsonContent['subject']);
        self::assertArrayHasKey('links', $jsonContent);

        $selfLinks = array_values(array_filter($jsonContent['links'], fn ($link) => 'self' === $link['rel']));
        self::assertCount(1, $selfLinks);
        self::assertEquals('application/activity+json', $selfLinks[0]['type']);

        $this->client->request('GET', $selfLinks[0]['href'], [], [], [
            'HTTP_ACCEPT' => 'application/activity+json',
        ]);

        self::assertResponseIsSuccessful();
        $jsonContent = self::getJsonResponse($this->client);
        self::assertEquals('Group', $jsonContent['type']);
        self::assertEquals($magazine->name, $jsonContent['preferredUsername']);
        self::assertEquals($selfLinks[0]['href'], $jsonContent['id']);
    }

    /**
     * The magazine called "random" is the local catch-all for content that belongs to no other
     * magazine. It is deliberately kept out of federation entirely (see issue #444), so it must
     * not be discoverable by handle either.
     */
    public function testRandomMagazineIsNotDiscoverable(): void
    {
        $this->getMagazineByName('random');
        $domain = $this->settingsManager->get('KBIN_DOMAIN');
        $resource = "acct:random@$domain";
        $this->client->request('GET', "https://$domain/.well-known/webfinger?resource=".urlencode($resource));

        self::assertResponseStatusCodeSame(404);
    }
}
