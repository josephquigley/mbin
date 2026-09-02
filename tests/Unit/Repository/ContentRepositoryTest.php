<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Entity\Magazine;
use App\Entity\User;
use App\Repository\ContentRepository;
use App\Repository\Criteria;
use PHPUnit\Framework\TestCase;

class ContentRepositoryTest extends TestCase
{
    public function testBroadCriteriaSkipTheCountQuery(): void
    {
        self::assertTrue(ContentRepository::canSkipCountQuery($this->criteria()));
    }

    public function testSubscribedCriteriaDoNotSkipTheCountQuery(): void
    {
        $criteria = $this->criteria();
        $criteria->subscribed = true;

        self::assertFalse(ContentRepository::canSkipCountQuery($criteria));
    }

    public function testModeratedCriteriaDoNotSkipTheCountQuery(): void
    {
        $criteria = $this->criteria();
        $criteria->moderated = true;

        self::assertFalse(ContentRepository::canSkipCountQuery($criteria));
    }

    public function testFavouriteCriteriaDoNotSkipTheCountQuery(): void
    {
        $criteria = $this->criteria();
        $criteria->favourite = true;

        self::assertFalse(ContentRepository::canSkipCountQuery($criteria));
    }

    public function testMagazineCriteriaDoNotSkipTheCountQuery(): void
    {
        $criteria = $this->criteria();
        $criteria->magazine = $this->createStub(Magazine::class);

        self::assertFalse(ContentRepository::canSkipCountQuery($criteria));
    }

    public function testUserCriteriaDoNotSkipTheCountQuery(): void
    {
        $criteria = $this->criteria();
        $criteria->user = $this->createStub(User::class);

        self::assertFalse(ContentRepository::canSkipCountQuery($criteria));
    }

    public function testDomainCriteriaDoNotSkipTheCountQuery(): void
    {
        $criteria = $this->criteria();
        $criteria->setDomain('example.com');

        self::assertFalse(ContentRepository::canSkipCountQuery($criteria));
    }

    public function testTagCriteriaDoNotSkipTheCountQuery(): void
    {
        $criteria = $this->criteria();
        $criteria->setTag('cats');

        self::assertFalse(ContentRepository::canSkipCountQuery($criteria));
    }

    public function testLanguageFilteredCriteriaDoNotSkipTheCountQuery(): void
    {
        $criteria = $this->criteria();
        $criteria->addLanguage('de');

        self::assertFalse(ContentRepository::canSkipCountQuery($criteria));
    }

    public function testTimeLimitedCriteriaDoNotSkipTheCountQuery(): void
    {
        $criteria = $this->criteria();
        $criteria->setTime(Criteria::TIME_DAY);

        self::assertFalse(ContentRepository::canSkipCountQuery($criteria));
    }

    public function testFederationLimitedCriteriaDoNotSkipTheCountQuery(): void
    {
        $criteria = $this->criteria();
        $criteria->setFederation(Criteria::AP_LOCAL);

        self::assertFalse(ContentRepository::canSkipCountQuery($criteria));
    }

    public function testTypeLimitedCriteriaDoNotSkipTheCountQuery(): void
    {
        $criteria = $this->criteria();
        $criteria->setType('article');

        self::assertFalse(ContentRepository::canSkipCountQuery($criteria));
    }

    private function criteria(): Criteria
    {
        return new class(1) extends Criteria {
        };
    }
}
