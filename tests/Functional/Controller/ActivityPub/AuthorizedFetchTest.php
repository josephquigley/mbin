<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\ActivityPub;

use App\Tests\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class AuthorizedFetchTest extends WebTestCase
{
    private const string AP_ACCEPT = 'application/activity+json';
    private const string REMOTE_DOMAIN = 'remote.example';
    private const string REMOTE_ACTOR = 'https://remote.example/u/peer';
    private const string REMOTE_KEY_ID = 'https://remote.example/u/peer#main-key';

    private \OpenSSLAsymmetricKey $privateKey;

    public function setUp(): void
    {
        parent::setUp();

        $this->getUserByUsername('user');

        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($key);
        $this->privateKey = $key;

        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);

        $this->testingApHttpClient->actorObjects[self::REMOTE_ACTOR] = [
            'id' => self::REMOTE_ACTOR,
            'type' => 'Person',
            'preferredUsername' => 'peer',
            'inbox' => self::REMOTE_ACTOR.'/inbox',
            'publicKey' => [
                'id' => self::REMOTE_KEY_ID,
                'owner' => self::REMOTE_ACTOR,
                'publicKeyPem' => $details['key'],
            ],
        ];
    }

    public function testUnsignedApGetSucceedsWhenAuthorizedFetchIsOff(): void
    {
        $this->settingsManager->set('MBIN_AUTHORIZED_FETCH', false);

        $this->client->request('GET', '/u/user', server: ['HTTP_ACCEPT' => self::AP_ACCEPT]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/activity+json');
    }

    public function testUnsignedApGetIsRefusedWhenAuthorizedFetchIsOn(): void
    {
        $this->settingsManager->set('MBIN_AUTHORIZED_FETCH', true);

        $this->client->request('GET', '/u/user', server: ['HTTP_ACCEPT' => self::AP_ACCEPT]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testMalformedSignatureHeaderIsRefused(): void
    {
        $this->settingsManager->set('MBIN_AUTHORIZED_FETCH', true);

        $this->client->request('GET', '/u/user', server: [
            'HTTP_ACCEPT' => self::AP_ACCEPT,
            'HTTP_SIGNATURE' => 'this is not a signature header',
            'HTTP_DATE' => gmdate('D, d M Y H:i:s \G\M\T'),
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testSignedApGetSucceedsWhenAuthorizedFetchIsOn(): void
    {
        $this->settingsManager->set('MBIN_AUTHORIZED_FETCH', true);

        $this->requestSigned('/u/user');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/activity+json');
    }

    public function testSignedApGetWithAQueryStringSucceeds(): void
    {
        $this->settingsManager->set('MBIN_AUTHORIZED_FETCH', true);

        $this->requestSigned('/u/user/outbox?page=1');

        self::assertResponseIsSuccessful();
    }

    public function testSignatureOverADifferentPathIsRefused(): void
    {
        $this->settingsManager->set('MBIN_AUTHORIZED_FETCH', true);

        $this->requestSigned('/u/user', signedTarget: '/u/somebody-else');

        self::assertResponseStatusCodeSame(401);
    }

    public function testSignatureNamingADigestThatIsNotPresentIsRefused(): void
    {
        $this->settingsManager->set('MBIN_AUTHORIZED_FETCH', true);

        $this->requestSigned('/u/user', signedHeaders: '(request-target) host date digest');

        self::assertResponseStatusCodeSame(401);
    }

    public function testSignedApGetFromAnAllowedInstanceSucceeds(): void
    {
        $this->settingsManager->set('MBIN_AUTHORIZED_FETCH', true);
        $this->settingsManager->set('MBIN_USE_FEDERATION_ALLOW_LIST', true);
        $instance = $this->instanceRepository->getOrCreateInstance(self::REMOTE_DOMAIN);
        $this->instanceManager->allowInstanceFederation($instance);

        $this->requestSigned('/u/user');

        self::assertResponseIsSuccessful();
    }

    public function testSignedApGetFromANonAllowedInstanceIsRefused(): void
    {
        $this->settingsManager->set('MBIN_AUTHORIZED_FETCH', true);
        $this->settingsManager->set('MBIN_USE_FEDERATION_ALLOW_LIST', true);

        $this->requestSigned('/u/user');

        self::assertResponseStatusCodeSame(401);
    }

    public function testSignedApGetFromADefederatedInstanceIsRefused(): void
    {
        $this->settingsManager->set('MBIN_AUTHORIZED_FETCH', true);
        $this->settingsManager->set('MBIN_USE_FEDERATION_ALLOW_LIST', false);
        $instance = $this->instanceRepository->getOrCreateInstance(self::REMOTE_DOMAIN);
        $this->instanceManager->banInstance($instance);

        $this->requestSigned('/u/user');

        self::assertResponseStatusCodeSame(401);
    }

    public function testInstanceActorStaysReachableUnsigned(): void
    {
        $this->settingsManager->set('MBIN_AUTHORIZED_FETCH', true);

        $this->client->request('GET', '/i/actor', server: ['HTTP_ACCEPT' => self::AP_ACCEPT]);

        self::assertResponseIsSuccessful();
    }

    /**
     * @param non-empty-string $path
     */
    #[DataProvider('provideUngatedDiscoveryPaths')]
    public function testDiscoveryEndpointsStayReachableUnsigned(string $path): void
    {
        $this->settingsManager->set('MBIN_AUTHORIZED_FETCH', true);

        $this->client->request('GET', $path, server: ['HTTP_ACCEPT' => self::AP_ACCEPT]);

        self::assertResponseIsSuccessful();
    }

    /**
     * @return list<array{string}>
     */
    public static function provideUngatedDiscoveryPaths(): array
    {
        return [
            ['/.well-known/nodeinfo'],
            ['/nodeinfo/2.0.json'],
            ['/contexts.jsonld'],
        ];
    }

    public function testRestApiStaysReachable(): void
    {
        $this->settingsManager->set('MBIN_AUTHORIZED_FETCH', true);

        $this->client->request('GET', '/api/instance');

        self::assertResponseIsSuccessful();
    }

    public function testHtmlRequestsAreUntouched(): void
    {
        $this->settingsManager->set('MBIN_AUTHORIZED_FETCH', true);

        $this->client->request('GET', '/u/user', server: ['HTTP_ACCEPT' => 'text/html']);

        self::assertResponseIsSuccessful();
    }

    private function requestSigned(
        string $path,
        ?string $signedTarget = null,
        string $signedHeaders = '(request-target) host date',
    ): void {
        $host = 'localhost';
        $date = gmdate('D, d M Y H:i:s \G\M\T');

        $values = [
            '(request-target)' => 'get '.($signedTarget ?? $path),
            'host' => $host,
            'date' => $date,
            'digest' => 'SHA-256=deadbeef',
        ];

        $lines = [];
        foreach (explode(' ', $signedHeaders) as $header) {
            $lines[] = $header.': '.$values[$header];
        }

        openssl_sign(implode("\n", $lines), $signed, $this->privateKey, OPENSSL_ALGO_SHA256);

        $signature = \sprintf(
            'keyId="%s",algorithm="rsa-sha256",headers="%s",signature="%s"',
            self::REMOTE_KEY_ID,
            $signedHeaders,
            base64_encode($signed)
        );

        $this->client->request('GET', $path, server: [
            'HTTP_ACCEPT' => self::AP_ACCEPT,
            'HTTP_HOST' => $host,
            'HTTP_DATE' => $date,
            'HTTP_SIGNATURE' => $signature,
        ]);
    }
}
