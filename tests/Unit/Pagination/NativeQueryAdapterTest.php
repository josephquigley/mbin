<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pagination;

use App\Pagination\NativeQueryAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NativeQueryAdapterTest extends TestCase
{
    #[DataProvider('provideCountsAndTtls')]
    public function testTtlForCount(int $count, string $expected): void
    {
        $ttl = NativeQueryAdapter::ttlForCount($count);

        self::assertSame($expected, $this->format($ttl));
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function provideCountsAndTtls(): array
    {
        return [
            'an empty result set is cached briefly' => [0, 'PT3M'],
            'a single page is cached briefly' => [3, 'PT3M'],
            'the small tier reaches up to 1000' => [1000, 'PT3M'],
            'above 1000 the existing tier applies' => [1001, 'PT10M'],
            'above 10000 the existing tier applies' => [10001, 'PT1H'],
            'above 25000 the existing tier applies' => [25001, 'PT6H'],
        ];
    }

    private function format(\DateInterval $ttl): string
    {
        return match (true) {
            $ttl->h > 0 => \sprintf('PT%dH', $ttl->h),
            $ttl->i > 0 => \sprintf('PT%dM', $ttl->i),
            default => \sprintf('PT%dS', $ttl->s),
        };
    }
}
