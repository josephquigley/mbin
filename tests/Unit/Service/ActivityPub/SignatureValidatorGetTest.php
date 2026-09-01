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
    private function signedHeaders(string $requestTarget, string $signedHeaders, ?string $date = null): array
    {
        $date ??= gmdate('D, d M Y H:i:s \G\M\T');

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

    // ─── Minimal header sets ────────────────────────────────────────────
    //
    // These tests PIN CURRENT BEHAVIOUR. They record what this code accepts
    // today; none of them asserts that accepting it is correct.
    //
    // validateGetRequest() requires the Signature and Date headers to be
    // PRESENT on the request, but verifyGetSignature() builds its signing
    // string from whatever the signature's own headers="..." list names. It
    // imposes no minimum, so a peer may send a Date header and decline to sign
    // it, and may decline to sign Host. Neither is covered by any other test,
    // which is how the gap stayed invisible.
    //
    // The practical consequence is that a captured signed GET can be replayed:
    // nothing binds the signature to a moment (no skew check, see the @todo in
    // validateGetRequest) or, when Host is unsigned, to this host.
    //
    // This is NOT a regression introduced by authorized fetch. The existing
    // POST path in validate() has the same properties, which that @todo says
    // outright. Requiring a minimum covered set and a clock-skew window is a
    // deliberate change with an interoperability cost, and belongs in its own
    // patch. If that patch lands, these tests SHOULD fail: their failure is the
    // signal to rewrite them, not to relax the new rule.

    /**
     * A signature covering only (request-target) is accepted, provided a Date
     * header is present on the request. Neither Host nor Date is signed.
     */
    public function testItAcceptsASignatureCoveringOnlyTheRequestTarget(): void
    {
        $headers = $this->signedHeaders('get /u/user', '(request-target)');

        $sut = $this->validator(self::$publicKeyPem);

        self::assertSame(self::ACTOR, $sut->validateGetRequest('/u/user', $headers));
    }

    /**
     * Host signed, Date present but unsigned. Accepted.
     */
    public function testItAcceptsASignatureThatDoesNotCoverTheDateHeader(): void
    {
        $headers = $this->signedHeaders('get /u/user', '(request-target) host');

        $sut = $this->validator(self::$publicKeyPem);

        self::assertSame(self::ACTOR, $sut->validateGetRequest('/u/user', $headers));
    }

    /**
     * Date signed, Host neither signed nor required. Accepted.
     */
    public function testItAcceptsASignatureThatDoesNotCoverTheHostHeader(): void
    {
        $headers = $this->signedHeaders('get /u/user', '(request-target) date');

        $sut = $this->validator(self::$publicKeyPem);

        self::assertSame(self::ACTOR, $sut->validateGetRequest('/u/user', $headers));
    }

    /**
     * A Date far in the past is accepted even when the signature covers it: the
     * value is never compared against the clock. This is the replay window, and
     * it is currently unbounded.
     */
    public function testItAcceptsAStaleDate(): void
    {
        $headers = $this->signedHeaders('get /u/user', '(request-target) host date', 'Tue, 01 Jan 2019 00:00:00 GMT');

        $sut = $this->validator(self::$publicKeyPem);

        self::assertSame(self::ACTOR, $sut->validateGetRequest('/u/user', $headers));
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
