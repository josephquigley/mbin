<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ActivityPub;

use App\Exception\InvalidApSignatureException;
use App\Exception\InvalidUserPublicKeyException;
use App\Service\ActivityPub\ApHttpClientInterface;
use App\Service\ActivityPub\SignatureValidator;
use App\Service\ActivityPubManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the GET verification path used by authorized fetch.
 *
 * A GET carries no body, so nothing here may depend on a Digest header.
 */
class SignatureValidatorGetTest extends TestCase
{
    private const string ACTOR = 'https://remote.example/u/peer';
    private const string KEY_ID = 'https://remote.example/u/peer#main-key';

    private static \OpenSSLAsymmetricKey $privateKey;
    private static string $publicKeyPem;
    private static string $otherPublicKeyPem;

    public static function setUpBeforeClass(): void
    {
        self::$privateKey = self::generateKey($pem);
        self::$publicKeyPem = $pem;

        self::generateKey($otherPem);
        self::$otherPublicKeyPem = $otherPem;
    }

    private static function generateKey(?string &$publicKeyPem): \OpenSSLAsymmetricKey
    {
        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if (false === $res) {
            self::fail('Unable to generate suitable RSA key, ensure your testing environment has a correctly configured OpenSSL library');
        }

        $details = openssl_pkey_get_details($res);
        $publicKeyPem = $details['key'];

        return $res;
    }

    public function testItValidatesACorrectlySignedGet(): void
    {
        $headers = $this->signedHeaders('get /u/user', '(request-target) host date');

        $sut = $this->validator(self::$publicKeyPem);

        self::assertSame(self::ACTOR, $sut->validateGetRequest('/u/user', $headers));
    }

    public function testItValidatesASignedGetWithAQueryString(): void
    {
        $headers = $this->signedHeaders('get /u/user/outbox?page=1', '(request-target) host date');

        $sut = $this->validator(self::$publicKeyPem);

        self::assertSame(self::ACTOR, $sut->validateGetRequest('/u/user/outbox?page=1', $headers));
    }

    public function testItRejectsASignatureMadeWithAnotherKey(): void
    {
        $headers = $this->signedHeaders('get /u/user', '(request-target) host date');

        $sut = $this->validator(self::$otherPublicKeyPem);

        $this->expectException(InvalidApSignatureException::class);
        $sut->validateGetRequest('/u/user', $headers);
    }

    public function testItRejectsASignatureOverADifferentRequestTarget(): void
    {
        $headers = $this->signedHeaders('get /u/somebody-else', '(request-target) host date');

        $sut = $this->validator(self::$publicKeyPem);

        $this->expectException(InvalidApSignatureException::class);
        $sut->validateGetRequest('/u/user', $headers);
    }

    /**
     * A GET has no body, so a digest cannot be computed. If the peer says it
     * signed one, and did not send it, the signature is unverifiable. It must
     * not be filled in with the digest of the empty string.
     */
    public function testItRejectsASignedDigestThatIsNotOnTheRequest(): void
    {
        $headers = $this->signedHeaders('get /u/user', '(request-target) host date digest');
        unset($headers['digest']);

        $sut = $this->validator(self::$publicKeyPem);

        $this->expectException(InvalidApSignatureException::class);
        $sut->validateGetRequest('/u/user', $headers);
    }

    public function testItRejectsARequestWithoutASignatureHeader(): void
    {
        $sut = $this->validator(self::$publicKeyPem);

        $this->expectException(InvalidApSignatureException::class);
        $sut->validateGetRequest('/u/user', ['date' => [gmdate('D, d M Y H:i:s \G\M\T')]]);
    }

    public function testItRejectsARequestWithoutADateHeader(): void
    {
        $headers = $this->signedHeaders('get /u/user', '(request-target) host date');
        unset($headers['date']);

        $sut = $this->validator(self::$publicKeyPem);

        $this->expectException(InvalidApSignatureException::class);
        $sut->validateGetRequest('/u/user', $headers);
    }

    public function testItRejectsAnActorWithoutAUsablePublicKey(): void
    {
        $headers = $this->signedHeaders('get /u/user', '(request-target) host date');

        $sut = $this->validator(null);

        $this->expectException(InvalidUserPublicKeyException::class);
        $sut->validateGetRequest('/u/user', $headers);
    }

    /**
     * @return array<string, string[]>
     */
    private function signedHeaders(string $requestTarget, string $signedHeaders): array
    {
        $date = gmdate('D, d M Y H:i:s \G\M\T');

        $values = [
            '(request-target)' => $requestTarget,
            'host' => 'remote.example',
            'date' => $date,
            'digest' => 'SHA-256=deadbeef',
        ];

        $lines = [];
        foreach (explode(' ', $signedHeaders) as $header) {
            $lines[] = $header.': '.$values[$header];
        }

        openssl_sign(implode("\n", $lines), $signature, self::$privateKey, OPENSSL_ALGO_SHA256);

        return [
            'host' => ['remote.example'],
            'date' => [$date],
            'digest' => ['SHA-256=deadbeef'],
            'signature' => [\sprintf(
                'keyId="%s",algorithm="rsa-sha256",headers="%s",signature="%s"',
                self::KEY_ID,
                $signedHeaders,
                base64_encode($signature)
            )],
        ];
    }

    private function validator(?string $publicKeyPem): SignatureValidator
    {
        $apHttpClient = $this->createStub(ApHttpClientInterface::class);
        $apHttpClient->method('getActorObject')->willReturn(
            null !== $publicKeyPem
                ? ['publicKey' => ['publicKeyPem' => $publicKeyPem]]
                : ['id' => self::ACTOR]
        );

        return new SignatureValidator(
            $this->createStub(ActivityPubManager::class),
            $apHttpClient,
            $this->createStub(LoggerInterface::class),
        );
    }
}
