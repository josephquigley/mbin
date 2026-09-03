<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * Every named rate limiter a controller asks for has to exist in the default
 * configuration. A limiter that is only defined under config/packages/test/
 * resolves during the test suite and fails at runtime on a real instance, which
 * is how POST /api/moderate/magazine/new came to answer 500 on every request.
 */
class RateLimiterConfigurationTest extends TestCase
{
    public function testEveryInjectedLimiterIsDefinedInTheDefaultConfiguration(): void
    {
        $defined = self::definedLimiters();

        $missing = [];
        foreach (self::injectedLimiters() as $name => $locations) {
            if (!\in_array($name, $defined, true)) {
                $missing[$name] = $locations;
            }
        }

        self::assertSame([], $missing, \sprintf(
            'These rate limiters are injected into a controller but not defined in config/packages/rate_limiter.yaml: %s',
            json_encode($missing, \JSON_PRETTY_PRINT)
        ));
    }

    /**
     * @return string[] the limiter names defined in the default configuration
     */
    private static function definedLimiters(): array
    {
        $config = Yaml::parseFile(self::projectDir().'/config/packages/rate_limiter.yaml');

        return array_keys($config['framework']['rate_limiter']);
    }

    /**
     * @return array<string, string[]> limiter name to the files injecting it
     */
    private static function injectedLimiters(): array
    {
        $finder = (new Finder())->files()->in(self::projectDir().'/src')->name('*.php');

        $injected = [];
        foreach ($finder as $file) {
            preg_match_all(
                '/RateLimiterFactoryInterface\s+\$([a-zA-Z]+)Limiter\b/',
                $file->getContents(),
                $matches
            );

            foreach ($matches[1] as $argument) {
                $injected[self::snakeCase($argument)][] = $file->getRelativePathname();
            }
        }

        return $injected;
    }

    private static function snakeCase(string $camelCase): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $camelCase));
    }

    private static function projectDir(): string
    {
        return \dirname(__DIR__, 2);
    }
}
