<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A service that helps to retrieve project information, like current version or project name.
 */
class ProjectInfoService
{
    private const NAME = 'mbin';
    private const CANONICAL_NAME = 'Mbin';
    private const REPOSITORY_URL = 'https://github.com/MbinOrg/mbin';

    public function __construct(
        private readonly string $kbinDomain,
        private readonly string $mbinVersion,
    ) {
    }

    /**
     * Get Mbin current project version.
     *
     * @return string version
     */
    public function getVersion(): string
    {
        return $this->mbinVersion;
    }

    /**
     * Get project name.
     *
     * @return string name
     */
    public function getName(): string
    {
        return self::NAME;
    }

    /**
     * Get project canonical name.
     *
     * @return string canonical name
     */
    public function getCanonicalName(): string
    {
        return self::CANONICAL_NAME;
    }

    /**
     * Get user-agent name usable as HTTP client requests.
     *
     * @return string user-agent
     */
    public function getUserAgent(): string
    {
        return "{$this->getCanonicalName()}/{$this->getVersion()} (+https://{$this->kbinDomain}/agent)";
    }

    /**
     * Get Mbin repository URL.
     *
     * @return string URL
     */
    public function getRepositoryURL(): string
    {
        return self::REPOSITORY_URL;
    }
}
