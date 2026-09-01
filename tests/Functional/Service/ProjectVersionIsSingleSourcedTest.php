<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Service\ProjectInfoService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The version must have exactly one definition.
 *
 * It used to have two: a private const in ProjectInfoService and a literal in the
 * http_client User-Agent in config/packages/framework.yaml, kept in step by a
 * comment asking the next person to remember. They drifted, and the failure is
 * silent and externally visible — getVersion() feeds nodeinfo, the REST API's
 * softwareVersion and the web UI, while the User-Agent is what peers log. An
 * instance can therefore report one version to a peer reading nodeinfo and a
 * different one to a peer reading its request log.
 *
 * Both now resolve from the mbin_version container parameter. These tests fail if
 * anyone reintroduces a second definition.
 */
class ProjectVersionIsSingleSourcedTest extends KernelTestCase
{
    public function testTheServiceReportsTheVersionParameter(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $version = $container->getParameter('mbin_version');

        self::assertIsString($version);
        self::assertNotSame('', $version);
        self::assertSame($version, $container->get(ProjectInfoService::class)->getVersion());
    }

    /**
     * The User-Agent is assembled in two unrelated places: by the service for its
     * own callers, and by framework.yaml for the shared http_client. This reads
     * the real configuration file rather than restating its contents, so that a
     * literal reintroduced there fails the test instead of hiding behind a third
     * copy of the format.
     */
    public function testTheHttpClientUserAgentMatchesTheServiceUserAgent(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $config = Yaml::parseFile($container->getParameter('kernel.project_dir').'/config/packages/framework.yaml');
        $configured = $config['framework']['http_client']['default_options']['headers']['User-Agent'] ?? null;

        self::assertIsString($configured, 'The http_client User-Agent is no longer where this test looks for it.');

        // Container parameters are written as %name% in configuration.
        $resolved = preg_replace_callback(
            '/%([a-z0-9_.]+)%/i',
            static fn (array $m): string => (string) $container->getParameter($m[1]),
            $configured
        );

        self::assertSame($container->get(ProjectInfoService::class)->getUserAgent(), $resolved);
    }
}
