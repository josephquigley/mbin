<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api\Magazine\Admin;

use App\Tests\Functional\Controller\Api\Magazine\MagazineRetrieveApiTest;
use App\Tests\WebTestCase;

class MagazineCreateApiTest extends WebTestCase
{
    public function testApiCannotCreateMagazineAnonymous(): void
    {
        $this->client->request('POST', '/api/moderate/magazine/new');

        self::assertResponseStatusCodeSame(401);
    }

    public function testApiCannotCreateMagazineWithoutScope(): void
    {
        $this->client->loginUser($this->getUserByUsername('JohnDoe'));
        self::createOAuth2AuthCodeClient();

        $codes = self::getAuthorizationCodeTokenResponse($this->client);
        $token = $codes['token_type'].' '.$codes['access_token'];

        $this->client->request('POST', '/api/moderate/magazine/new', server: ['HTTP_AUTHORIZATION' => $token]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testApiCanCreateMagazine(): void
    {
        $user = $this->getUserByUsername('JohnDoe');
        $this->client->loginUser($user);
        self::createOAuth2AuthCodeClient();

        $codes = self::getAuthorizationCodeTokenResponse($this->client, scopes: 'read write moderate:magazine_admin:create');
        $token = $codes['token_type'].' '.$codes['access_token'];

        $name = 'test';
        $title = 'API Test Magazine';
        $description = 'A description';

        $this->client->jsonRequest(
            'POST', '/api/moderate/magazine/new',
            parameters: [
                'name' => $name,
                'title' => $title,
                'description' => $description,
                'isAdult' => false,
                'discoverable' => false,
                'isPostingRestrictedToMods' => true,
                'indexable' => false,
            ],
            server: ['HTTP_AUTHORIZATION' => $token]
        );

        self::assertResponseStatusCodeSame(201);
        $jsonData = self::getJsonResponse($this->client);

        self::assertIsArray($jsonData);
        self::assertArrayKeysMatch(MagazineRetrieveApiTest::MAGAZINE_RESPONSE_KEYS, $jsonData);
        self::assertEquals($name, $jsonData['name']);
        self::assertSame($user->getId(), $jsonData['owner']['userId']);
        self::assertEquals($description, $jsonData['description']);
        self::assertEquals($rules, $jsonData['rules']);
        self::assertFalse($jsonData['isAdult']);
        self::assertFalse($jsonData['discoverable']);
        self::assertTrue($jsonData['isPostingRestrictedToMods']);
        self::assertFalse($jsonData['indexable']);
    }

    public function testApiCannotCreateInvalidMagazine(): void
    {
        $user = $this->getUserByUsername('JohnDoe');
        $this->client->loginUser($user);
        self::createOAuth2AuthCodeClient();

        $codes = self::getAuthorizationCodeTokenResponse($this->client, scopes: 'read write moderate:magazine_admin:create');
        $token = $codes['token_type'].' '.$codes['access_token'];

        $title = 'No name';
        $description = 'A description';

        $this->client->jsonRequest(
            'POST', '/api/moderate/magazine/new',
            parameters: [
                'name' => null,
                'title' => $title,
                'description' => $description,
                'isAdult' => false,
            ],
            server: ['HTTP_AUTHORIZATION' => $token]
        );

        self::assertResponseStatusCodeSame(400);

        $name = 'a';
        $title = 'Too short name';

        $this->client->jsonRequest(
            'POST', '/api/moderate/magazine/new',
            parameters: [
                'name' => $name,
                'title' => $title,
                'description' => $description,
                'isAdult' => false,
            ],
            server: ['HTTP_AUTHORIZATION' => $token]
        );

        self::assertResponseStatusCodeSame(400);

        $name = 'long_name_that_exceeds_the_limit';
        $title = 'Too long name';
        $this->client->jsonRequest(
            'POST', '/api/moderate/magazine/new',
            parameters: [
                'name' => $name,
                'title' => $title,
                'description' => $description,
                'isAdult' => false,
            ],
            server: ['HTTP_AUTHORIZATION' => $token]
        );

        self::assertResponseStatusCodeSame(400);

        $name = 'invalidch@racters!';
        $title = 'Invalid Characters in name';
        $this->client->jsonRequest(
            'POST', '/api/moderate/magazine/new',
            parameters: [
                'name' => $name,
                'title' => $title,
                'description' => $description,
                'isAdult' => false,
            ],
            server: ['HTTP_AUTHORIZATION' => $token]
        );

        self::assertResponseStatusCodeSame(400);

        $name = 'nulltitle';
        $title = null;
        $this->client->jsonRequest(
            'POST', '/api/moderate/magazine/new',
            parameters: [
                'name' => $name,
                'title' => $title,
                'description' => $description,
                'isAdult' => false,
            ],
            server: ['HTTP_AUTHORIZATION' => $token]
        );

        self::assertResponseStatusCodeSame(400);

        $name = 'shorttitle';
        $title = 'as';
        $this->client->jsonRequest(
            'POST', '/api/moderate/magazine/new',
            parameters: [
                'name' => $name,
                'title' => $title,
                'description' => $description,
                'isAdult' => false,
            ],
            server: ['HTTP_AUTHORIZATION' => $token]
        );

        self::assertResponseStatusCodeSame(400);

        $name = 'longtitle';
        $title = 'Way too long of a title. This can only be 50 characters!';
        $this->client->jsonRequest(
            'POST', '/api/moderate/magazine/new',
            parameters: [
                'name' => $name,
                'title' => $title,
                'description' => $description,
                'isAdult' => false,
            ],
            server: ['HTTP_AUTHORIZATION' => $token]
        );

        self::assertResponseStatusCodeSame(400);

        $name = 'rulesDeprecated';
        $title = 'rules are deprecated';
        $rules = 'Some rules';
        $this->client->jsonRequest(
            'POST', '/api/moderate/magazine/new',
            parameters: [
                'name' => $name,
                'title' => $title,
                'rules' => $rules,
                'isAdult' => false,
            ],
            server: ['HTTP_AUTHORIZATION' => $token]
        );

        self::assertResponseStatusCodeSame(400);
    }

    private const INVALID_NAME_GUIDANCE = 'No spaces or hyphens allowed. Use only letters, numbers, and underscores (2–25 characters).';

    private function getCreateMagazineToken(): string
    {
        $user = $this->getUserByUsername('JohnDoe');
        $this->client->loginUser($user);
        self::createOAuth2AuthCodeClient();

        $codes = self::getAuthorizationCodeTokenResponse($this->client, scopes: 'read write moderate:magazine_admin:create');

        return $codes['token_type'].' '.$codes['access_token'];
    }

    public function testApiCannotCreateMagazineWithSpacesInName(): void
    {
        $token = $this->getCreateMagazineToken();

        $this->client->jsonRequest(
            'POST', '/api/moderate/magazine/new',
            parameters: [
                'name' => 'My Community',
                'title' => 'My Community',
                'isAdult' => false,
            ],
            server: ['HTTP_AUTHORIZATION' => $token]
        );

        self::assertResponseStatusCodeSame(400);

        $jsonData = self::getJsonResponse($this->client);
        self::assertIsArray($jsonData);
        self::assertArrayHasKey('title', $jsonData);
        self::assertEquals('An error occurred', $jsonData['title']);
        self::assertArrayHasKey('status', $jsonData);
        self::assertEquals(400, $jsonData['status']);
        self::assertArrayHasKey('detail', $jsonData);
        // The actionable guidance must reach the client, not a generic "Bad Request".
        self::assertNotEquals('Bad Request', $jsonData['detail']);
        self::assertStringContainsString(self::INVALID_NAME_GUIDANCE, $jsonData['detail']);
        self::assertEquals(self::INVALID_NAME_GUIDANCE.' Try: My_Community.', $jsonData['detail']);

        // The previously fixed apiMagazineLimiter behavior is unaffected: rate limit headers are still present.
        self::assertResponseHasHeader('X-RateLimit-Limit');
    }

    public function testApiCannotCreateMagazineWithHyphenInName(): void
    {
        $token = $this->getCreateMagazineToken();

        $this->client->jsonRequest(
            'POST', '/api/moderate/magazine/new',
            parameters: [
                'name' => 'My-Community',
                'title' => 'My Community',
                'isAdult' => false,
            ],
            server: ['HTTP_AUTHORIZATION' => $token]
        );

        self::assertResponseStatusCodeSame(400);

        $jsonData = self::getJsonResponse($this->client);
        self::assertIsArray($jsonData);
        self::assertNotEquals('Bad Request', $jsonData['detail']);
        self::assertEquals(self::INVALID_NAME_GUIDANCE.' Try: My_Community.', $jsonData['detail']);
    }

    public function testApiFallsBackToGenericNameGuidanceForOtherInvalidCharacters(): void
    {
        $token = $this->getCreateMagazineToken();

        // "@" and "!" cannot be turned into a valid identifier with a simple transformation,
        // so no "Try:" suggestion is emitted, only the neutral example.
        $this->client->jsonRequest(
            'POST', '/api/moderate/magazine/new',
            parameters: [
                'name' => 'invalidch@racters!',
                'title' => 'Invalid Characters in name',
                'isAdult' => false,
            ],
            server: ['HTTP_AUTHORIZATION' => $token]
        );

        self::assertResponseStatusCodeSame(400);

        $jsonData = self::getJsonResponse($this->client);
        self::assertIsArray($jsonData);
        self::assertNotEquals('Bad Request', $jsonData['detail']);
        self::assertStringContainsString(self::INVALID_NAME_GUIDANCE, $jsonData['detail']);
        self::assertStringNotContainsString('Try:', $jsonData['detail']);
        self::assertEquals(self::INVALID_NAME_GUIDANCE.' Example: community_name.', $jsonData['detail']);
    }

    public function testApiReportsTooShortAndTooLongMagazineNames(): void
    {
        $token = $this->getCreateMagazineToken();

        $this->client->jsonRequest(
            'POST', '/api/moderate/magazine/new',
            parameters: [
                'name' => 'a',
                'title' => 'Too short name',
                'isAdult' => false,
            ],
            server: ['HTTP_AUTHORIZATION' => $token]
        );

        self::assertResponseStatusCodeSame(400);
        $jsonData = self::getJsonResponse($this->client);
        self::assertEquals('Community name must be at least 2 characters.', $jsonData['detail']);

        $this->client->jsonRequest(
            'POST', '/api/moderate/magazine/new',
            parameters: [
                'name' => 'long_name_that_exceeds_the_limit',
                'title' => 'Too long name',
                'isAdult' => false,
            ],
            server: ['HTTP_AUTHORIZATION' => $token]
        );

        self::assertResponseStatusCodeSame(400);
        $jsonData = self::getJsonResponse($this->client);
        self::assertEquals('Community name must be no more than 25 characters.', $jsonData['detail']);
    }

    public function testApiCanCreateMagazineWithUnderscoreInName(): void
    {
        $token = $this->getCreateMagazineToken();

        $name = 'My_Community';
        $title = 'My Community';

        $this->client->jsonRequest(
            'POST', '/api/moderate/magazine/new',
            parameters: [
                'name' => $name,
                'title' => $title,
                'description' => 'A description',
                'isAdult' => false,
                'discoverable' => false,
                'isPostingRestrictedToMods' => true,
                'indexable' => false,
            ],
            server: ['HTTP_AUTHORIZATION' => $token]
        );

        self::assertResponseStatusCodeSame(201);
        $jsonData = self::getJsonResponse($this->client);
        self::assertIsArray($jsonData);
        self::assertArrayKeysMatch(MagazineRetrieveApiTest::MAGAZINE_RESPONSE_KEYS, $jsonData);
        self::assertEquals($name, $jsonData['name']);
        self::assertEquals($title, $jsonData['title']);
    }
}
