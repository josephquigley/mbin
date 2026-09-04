<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Oidc;

use App\Provider\OidcResourceOwner;
use App\Service\Oidc\OidcAdminGroupPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OidcAdminGroupPolicyTest extends TestCase
{
    public function testDisabledWhenNoGroupIsConfigured(): void
    {
        $policy = new OidcAdminGroupPolicy(null);

        self::assertFalse($policy->isEnabled());
        self::assertFalse($policy->shouldPromote(['groups' => ['mbin-admins']], $this->owner(['groups' => ['mbin-admins']])));
    }

    #[DataProvider('emptyConfigurations')]
    public function testWhitespaceAndEmptyStringsAreTreatedAsUnset(?string $configured): void
    {
        self::assertFalse((new OidcAdminGroupPolicy($configured))->isEnabled());
    }

    /**
     * @return \Generator<array{?string}>
     */
    public static function emptyConfigurations(): \Generator
    {
        yield [null];
        yield [''];
        yield ['   '];
    }

    public function testPromotesOnTheIdTokenClaim(): void
    {
        $policy = new OidcAdminGroupPolicy('mbin-admins');

        self::assertTrue($policy->shouldPromote(['groups' => ['members', 'mbin-admins']], $this->owner([])));
    }

    public function testDoesNotPromoteWhenTheGroupIsAbsent(): void
    {
        $policy = new OidcAdminGroupPolicy('mbin-admins');

        self::assertFalse($policy->shouldPromote(['groups' => ['members']], $this->owner([])));
    }

    public function testFallsBackToUserinfoWhenTheIdTokenCarriesNoGroupsClaim(): void
    {
        $policy = new OidcAdminGroupPolicy('mbin-admins');

        self::assertTrue($policy->shouldPromote([], $this->owner(['groups' => ['mbin-admins']])));
    }

    /**
     * A present but empty claim is the provider answering the question. It
     * must not send us looking for a more agreeable answer in userinfo.
     */
    public function testAnEmptyIdTokenClaimIsNotOverriddenByUserinfo(): void
    {
        $policy = new OidcAdminGroupPolicy('mbin-admins');

        self::assertFalse($policy->shouldPromote(['groups' => []], $this->owner(['groups' => ['mbin-admins']])));
    }

    public function testAMalformedClaimDoesNotPromote(): void
    {
        $policy = new OidcAdminGroupPolicy('mbin-admins');

        self::assertFalse($policy->shouldPromote(['groups' => 'mbin-admins'], $this->owner([])));
        self::assertFalse($policy->shouldPromote(['groups' => [['mbin-admins']]], $this->owner([])));
    }

    public function testMatchingIsExactAndCaseSensitive(): void
    {
        $policy = new OidcAdminGroupPolicy('mbin-admins');

        self::assertFalse($policy->shouldPromote(['groups' => ['Mbin-Admins']], $this->owner([])));
        self::assertFalse($policy->shouldPromote(['groups' => ['mbin-admins-readonly']], $this->owner([])));
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function owner(array $claims): OidcResourceOwner
    {
        return new OidcResourceOwner($claims + ['sub' => 'user-1'], 'preferred_username');
    }
}
