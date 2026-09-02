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

    public function testAnEstimateAboveTheAssumedCountAllowsTheSkip(): void
    {
        self::assertTrue(ContentRepository::estimateAllowsSkip(25000, 25000));
        self::assertTrue(ContentRepository::estimateAllowsSkip(40000, 25000));
    }

    public function testAnEstimateBelowTheAssumedCountBlocksTheSkip(): void
    {
        self::assertFalse(ContentRepository::estimateAllowsSkip(24999, 25000));
        self::assertFalse(ContentRepository::estimateAllowsSkip(4, 25000));
        self::assertFalse(ContentRepository::estimateAllowsSkip(0, 25000));
    }

    public function testAnUnknownEstimateBlocksTheSkip(): void
    {
        // reltuples is -1 on a table that has never been analyzed, and the
        // lookup returns null when it cannot be trusted at all. Neither may be
        // read as "large": an unanalyzed table is the state a fresh instance
        // is in, which is exactly where the assumed count is most wrong.
        self::assertFalse(ContentRepository::estimateAllowsSkip(null, 25000));
    }

    public function testFeedTablesFollowTheUnionForCombinedContent(): void
    {
        $criteria = $this->criteria();
        $criteria->content = Criteria::CONTENT_COMBINED;
        $criteria->includeBoosts = false;

        self::assertSame(['entry', 'post'], ContentRepository::feedTables($criteria));
    }

    public function testFeedTablesIncludeCommentsWhenBoostsAreIncluded(): void
    {
        $criteria = $this->criteria();
        $criteria->content = Criteria::CONTENT_COMBINED;
        $criteria->includeBoosts = true;

        self::assertSame(
            ['entry', 'post', 'entry_comment', 'post_comment'],
            ContentRepository::feedTables($criteria)
        );
    }

    public function testFeedTablesForThreadsAreEntriesOnly(): void
    {
        $criteria = $this->criteria();
        $criteria->content = Criteria::CONTENT_THREADS;
        $criteria->includeBoosts = true;

        self::assertSame(['entry'], ContentRepository::feedTables($criteria));
    }

    public function testFeedTablesForMicroblogFollowTheBoostSetting(): void
    {
        $criteria = $this->criteria();
        $criteria->content = Criteria::CONTENT_MICROBLOG;
        $criteria->includeBoosts = false;
        self::assertSame(['post'], ContentRepository::feedTables($criteria));

        $criteria->includeBoosts = true;
        self::assertSame(['post', 'post_comment'], ContentRepository::feedTables($criteria));
    }

    private function criteria(): Criteria
    {
        return new class(1) extends Criteria {
        };
    }
}
