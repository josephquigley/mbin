<?php

declare(strict_types=1);

namespace App\Tests\Unit\Provider;

use App\Provider\OidcResourceOwner;
use PHPUnit\Framework\TestCase;

class OidcResourceOwnerTest extends TestCase
{
    public function testReadsStandardClaims(): void
    {
        $owner = new OidcResourceOwner([
            'sub' => 'user-1',
            'email' => 'someone@example.test',
            'preferred_username' => 'someone',
            'picture' => 'https://idp.test/avatar.png',
        ], 'preferred_username');

        self::assertSame('user-1', $owner->getId());
        self::assertSame('someone@example.test', $owner->getEmail());
        self::assertSame('someone', $owner->getUsername());
        self::assertSame('https://idp.test/avatar.png', $owner->getPictureUrl());
    }

    public function testUsernameClaimIsConfigurable(): void
    {
        $owner = new OidcResourceOwner(['sub' => 'user-1', 'nickname' => 'nick'], 'nickname');

        self::assertSame('nick', $owner->getUsername());
    }

    public function testFallsBackToSubWhenTheUsernameClaimIsAbsent(): void
    {
        $owner = new OidcResourceOwner(['sub' => 'user-1'], 'preferred_username');

        self::assertSame('user-1', $owner->getUsername());
    }

    public function testAbsentOptionalClaimsAreNull(): void
    {
        $owner = new OidcResourceOwner(['sub' => 'user-1'], 'preferred_username');

        self::assertNull($owner->getEmail());
        self::assertNull($owner->getPictureUrl());
    }

    public function testNonScalarClaimsAreIgnored(): void
    {
        $owner = new OidcResourceOwner(['sub' => 'user-1', 'email' => ['a@b.test']], 'preferred_username');

        self::assertNull($owner->getEmail());
    }

    public function testToArrayReturnsEveryClaim(): void
    {
        $claims = ['sub' => 'user-1', 'groups' => ['members']];

        self::assertSame($claims, (new OidcResourceOwner($claims, 'preferred_username'))->toArray());
    }
}
