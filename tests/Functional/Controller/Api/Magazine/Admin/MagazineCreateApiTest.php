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

    public function testApiCreateMagazineReportsWhyTheNameIsInvalid(): void
    {
        $token = $this->getCreateToken();

        $this->client->jsonRequest(
            'POST', '/api/moderate/magazine/new',
            parameters: [
                'name' => 'My Community',
                'title' => 'A name with a space',
                'isAdult' => false,
            ],
            server: ['HTTP_AUTHORIZATION' => $token]
        );

        self::assertResponseStatusCodeSame(400);
        $jsonData = self::getJsonResponse($this->client);

        self::assertArrayHasKey('violations', $jsonData);
        self::assertSame('name', $jsonData['violations'][0]['propertyPath']);
        self::assertStringContainsString('letters, numbers and underscores', $jsonData['violations'][0]['title']);
        self::assertStringContainsString('letters, numbers and underscores', $jsonData['detail']);

        $this->client->jsonRequest(
            'POST', '/api/moderate/magazine/new',
            parameters: [
                'name' => 'my-community',
                'title' => 'A name with a hyphen',
                'isAdult' => false,
            ],
            server: ['HTTP_AUTHORIZATION' => $token]
        );

        self::assertResponseStatusCodeSame(400);
        $jsonData = self::getJsonResponse($this->client);
        self::assertStringContainsString('letters, numbers and underscores', $jsonData['detail']);

        $this->client->jsonRequest(
            'POST', '/api/moderate/magazine/new',
            parameters: [
                'name' => 'invalidch@racters!',
                'title' => 'A name nothing can salvage',
                'isAdult' => false,
            ],
            server: ['HTTP_AUTHORIZATION' => $token]
        );

        self::assertResponseStatusCodeSame(400);
        $jsonData = self::getJsonResponse($this->client);
        self::assertStringContainsString('letters, numbers and underscores', $jsonData['detail']);
    }

    public function testApiCreateMagazineReportsNameLengthProblems(): void
    {
        $token = $this->getCreateToken();

        $this->client->jsonRequest(
            'POST', '/api/moderate/magazine/new',
            parameters: [
                'name' => 'a',
                'title' => 'Too short a name',
                'isAdult' => false,
            ],
            server: ['HTTP_AUTHORIZATION' => $token]
        );

        self::assertResponseStatusCodeSame(400);
        $jsonData = self::getJsonResponse($this->client);
        self::assertStringContainsString('at least 2 characters', $jsonData['detail']);

        $this->client->jsonRequest(
            'POST', '/api/moderate/magazine/new',
            parameters: [
                'name' => 'long_name_that_exceeds_the_limit',
                'title' => 'Too long a name',
                'isAdult' => false,
            ],
            server: ['HTTP_AUTHORIZATION' => $token]
        );

        self::assertResponseStatusCodeSame(400);
        $jsonData = self::getJsonResponse($this->client);
        self::assertStringContainsString('at most 25 characters', $jsonData['detail']);
    }

    public function testApiCreateMagazineDoesNotReportABlankNameAsTooShort(): void
    {
        $token = $this->getCreateToken();

        $this->client->jsonRequest(
            'POST', '/api/moderate/magazine/new',
            parameters: [
                'name' => '',
                'title' => 'A blank name',
                'isAdult' => false,
            ],
            server: ['HTTP_AUTHORIZATION' => $token]
        );

        self::assertResponseStatusCodeSame(400);
        $jsonData = self::getJsonResponse($this->client);

        // Regex returns early on an empty string, so a blank name is only ever reported
        // as blank, never as a character set problem.
        self::assertStringNotContainsString('letters, numbers and underscores', $jsonData['detail']);
        self::assertStringContainsString('should not be blank', $jsonData['detail']);
    }

    public function testApiCreateMagazineReportsADuplicateNameAsTaken(): void
    {
        $this->getMagazineByName('taken');
        $token = $this->getCreateToken();

        $this->client->jsonRequest(
            'POST', '/api/moderate/magazine/new',
            parameters: [
                'name' => 'taken',
                'title' => 'A name already in use',
                'isAdult' => false,
            ],
            server: ['HTTP_AUTHORIZATION' => $token]
        );

        self::assertResponseStatusCodeSame(400);
        $jsonData = self::getJsonResponse($this->client);

        self::assertSame('name', $jsonData['violations'][0]['propertyPath']);
        self::assertStringNotContainsString('letters, numbers and underscores', $jsonData['detail']);
    }

    public function testApiCanCreateMagazineWithUnderscoresInTheName(): void
    {
        $token = $this->getCreateToken();

        $this->client->jsonRequest(
            'POST', '/api/moderate/magazine/new',
            parameters: [
                'name' => 'My_Community',
                'title' => 'A valid name',
                'isAdult' => false,
            ],
            server: ['HTTP_AUTHORIZATION' => $token]
        );

        self::assertResponseStatusCodeSame(201);
        $jsonData = self::getJsonResponse($this->client);
        self::assertEquals('My_Community', $jsonData['name']);
    }

    private function getCreateToken(): string
    {
        $this->client->loginUser($this->getUserByUsername('JohnDoe'));
        self::createOAuth2AuthCodeClient();

        $codes = self::getAuthorizationCodeTokenResponse($this->client, scopes: 'read write moderate:magazine_admin:create');

        return $codes['token_type'].' '.$codes['access_token'];
    }
}
