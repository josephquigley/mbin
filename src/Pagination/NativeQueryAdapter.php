<?php

declare(strict_types=1);

namespace App\Pagination;

use App\Pagination\Transformation\ResultTransformer;
use App\Pagination\Transformation\VoidTransformer;
use App\Utils\SqlHelpers;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Statement;
use Pagerfanta\Adapter\AdapterInterface;
use Psr\Cache\CacheItemInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * This adapter only works if your sql does not define an :offset and a :limit parameter. These will be appended.
 */
class NativeQueryAdapter implements AdapterInterface
{
    private Statement $statement;

    /**
     * @param int|null          $numOfResults if this is null, then a query will be executed to get the number of results
     * @param ResultTransformer $transformer  defaults to the VoidTransformer which does not transform the result in any way
     *
     * @throws Exception
     */
    public function __construct(
        private readonly Connection $conn,
        string $sql,
        private readonly array $parameters,
        private ?int $numOfResults = null,
        private readonly ResultTransformer $transformer = new VoidTransformer(),
        private readonly ?CacheInterface $cache = null,
    ) {
        if (null === $this->numOfResults) {
            $this->numOfResults = $this->calculateNumOfResultsCached($sql, $this->parameters);
        }

        $this->statement = $this->conn->prepare($sql.' LIMIT :limit OFFSET :offset');
        foreach ($this->parameters as $key => $value) {
            $this->statement->bindValue($key, $value, SqlHelpers::getSqlType($value));
        }
    }

    private function calculateNumOfResultsCached(string $sql, array $parameters): int
    {
        if (null === $this->cache) {
            return $this->calculateNumOfResults($sql, $parameters);
        }
        $sqlHash = hash('sha256', $sql);
        $parameterHash = hash('sha256', print_r($parameters, true));

        return $this->cache->get("native_query_count_$sqlHash-$parameterHash", function (CacheItemInterface $item) use ($sql, $parameters) {
            $count = $this->calculateNumOfResults($sql, $parameters);
            $item->expiresAfter(self::ttlForCount($count));

            return $count;
        });
    }

    /**
     * How long a cached result count may be served for.
     *
     * The tiers grow with the count because a count query gets more expensive the more rows it
     * covers, so an expensive one is worth serving stale for longer. The smallest counts are the
     * cheapest to recompute, and they are also the ones where staleness is visible: a feed whose
     * count is remembered from when it held fewer items keeps reporting a maxPage that hides its
     * later pages. So they get the shortest lifetime rather than an unlimited one.
     */
    public static function ttlForCount(int $count): \DateInterval
    {
        return match (true) {
            $count > 25000 => new \DateInterval('PT6H'),
            $count > 10000 => new \DateInterval('PT1H'),
            $count > 1000 => new \DateInterval('PT10M'),
            default => new \DateInterval('PT3M'),
        };
    }

    private function calculateNumOfResults(string $sql, array $parameters): int
    {
        $sql2 = 'SELECT COUNT(*) as cnt FROM ('.$sql.') sub';
        $stmt2 = $this->conn->prepare($sql2);
        foreach ($parameters as $key => $value) {
            $stmt2->bindValue($key, $value, SqlHelpers::getSqlType($value));
        }
        $result = $stmt2->executeQuery()->fetchAllAssociative();

        return $result[0]['cnt'];
    }

    public function getNbResults(): int
    {
        return $this->numOfResults;
    }

    public function getSlice(int $offset, int $length): iterable
    {
        $this->statement->bindValue('offset', $offset);
        $this->statement->bindValue('limit', $length);

        return $this->transformer->transform($this->statement->executeQuery()->fetchAllAssociative());
    }
}
