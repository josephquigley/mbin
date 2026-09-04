<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Oidc;

use App\Service\Oidc\Exception\OidcValidationException;
use App\Service\Oidc\OidcTokenValidator;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;

class OidcTokenValidatorTest extends TestCase
{
    private const ISSUER = 'https://idp.test';
    private const CLIENT_ID = 'mbin';
    private const NONCE = 'nonce-value';

    private string $privateKey;
    private string $publicKey;

    protected function setUp(): void
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($resource, $privateKey);

        $this->privateKey = $privateKey;
        $this->publicKey = openssl_pkey_get_details($resource)['key'];
    }

    public function testValidTokenReturnsClaims(): void
    {
        $claims = $this->validator()->validate($this->token(), self::NONCE);

        self::assertSame('user-1', $claims['sub']);
    }

    public function testWrongIssuerIsRejected(): void
    {
        $this->expectException(OidcValidationException::class);
        $this->expectExceptionMessageMatches('/issuer/i');

        $this->validator()->validate($this->token(['iss' => 'https://evil.test']), self::NONCE);
    }

    public function testAudienceWithoutClientIdIsRejected(): void
    {
        $this->expectException(OidcValidationException::class);
        $this->expectExceptionMessageMatches('/audience/i');

        $this->validator()->validate($this->token(['aud' => 'someone-else']), self::NONCE);
    }

    public function testMultipleAudiencesWithoutAzpIsRejected(): void
    {
        $this->expectException(OidcValidationException::class);
        $this->expectExceptionMessageMatches('/azp/i');

        $this->validator()->validate($this->token(['aud' => [self::CLIENT_ID, 'other']]), self::NONCE);
    }

    public function testMultipleAudiencesWithMatchingAzpIsAccepted(): void
    {
        $claims = $this->validator()->validate(
            $this->token(['aud' => [self::CLIENT_ID, 'other'], 'azp' => self::CLIENT_ID]),
            self::NONCE,
        );

        self::assertSame('user-1', $claims['sub']);
    }

    public function testExpiredTokenIsRejected(): void
    {
        $this->expectException(OidcValidationException::class);

        $this->validator()->validate($this->token(['exp' => time() - 3600]), self::NONCE);
    }

    public function testFutureIssuedAtBeyondLeewayIsRejected(): void
    {
        $this->expectException(OidcValidationException::class);
        $this->expectExceptionMessageMatches('/iat/i');

        $this->validator()->validate($this->token(['iat' => time() + 3600]), self::NONCE);
    }

    public function testTokenSignedByAnotherKeyIsRejected(): void
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($resource, $otherPrivate);

        $this->expectException(OidcValidationException::class);

        $this->validator()->validate(JWT::encode($this->claims(), $otherPrivate, 'RS256', 'key-1'), self::NONCE);
    }

    public function testUnsignedTokenIsRejected(): void
    {
        $encode = static fn (array $value): string => rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $token = $encode(['alg' => 'none', 'typ' => 'JWT']).'.'.$encode($this->claims()).'.';

        $this->expectException(OidcValidationException::class);

        $this->validator()->validate($token, self::NONCE);
    }

    public function testNonceMismatchIsRejected(): void
    {
        $this->expectException(OidcValidationException::class);
        $this->expectExceptionMessageMatches('/nonce/i');

        $this->validator()->validate($this->token(), 'a-different-nonce');
    }

    public function testUnknownKeyIdIsRejected(): void
    {
        $this->expectException(OidcValidationException::class);

        $this->validator()->validate(JWT::encode($this->claims(), $this->privateKey, 'RS256', 'key-2'), self::NONCE);
    }

    public function testMissingSubjectIsRejected(): void
    {
        $claims = $this->claims();
        unset($claims['sub']);

        $this->expectException(OidcValidationException::class);
        $this->expectExceptionMessageMatches('/sub/i');

        $this->validator()->validate(JWT::encode($claims, $this->privateKey, 'RS256', 'key-1'), self::NONCE);
    }

    private function validator(): OidcTokenValidator
    {
        return new OidcTokenValidator(
            fn (): array => ['key-1' => new Key($this->publicKey, 'RS256')],
            self::ISSUER,
            self::CLIENT_ID,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function token(array $overrides = []): string
    {
        return JWT::encode($overrides + $this->claims(), $this->privateKey, 'RS256', 'key-1');
    }

    /**
     * @return array<string, mixed>
     */
    private function claims(): array
    {
        return [
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'sub' => 'user-1',
            'exp' => time() + 300,
            'iat' => time(),
            'nonce' => self::NONCE,
        ];
    }
}
