<?php

declare(strict_types=1);

namespace Woweb\VersionEndpoint\Http\Controllers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Woweb\VersionEndpoint\Support\DeployFacts;
use Woweb\VersionEndpoint\Support\RuntimeCollector;
use Woweb\VersionEndpoint\Support\VersionFilePath;

/**
 * Answers with what this application is actually running.
 *
 * The signature has already been verified by the route middleware, so anything
 * reaching this point is a known caller. The payload carries version metadata
 * only: no host names, no credentials, no schema and no configuration values.
 *
 * The field set is a published contract (see the README): adding a field is a
 * minor release, removing or renaming one is a major release.
 */
class VersionController
{
    public function __construct(
        private readonly Application $app,
        private readonly Repository $config,
    ) {}

    public function __invoke(): JsonResponse
    {
        $facts = DeployFacts::fromFile(VersionFilePath::resolve(
            (string) $this->config->get('version-endpoint.version_file'),
            $this->app->basePath(),
        ));

        $runtimes = new RuntimeCollector(
            (array) $this->config->get('version-endpoint.runtimes', []),
            (string) $this->config->get('version-endpoint.os_release_path'),
        );

        return new JsonResponse([
            'php' => PHP_VERSION,
            'framework' => $this->app->version(),
            'git_tag' => $facts->string('git_tag'),
            'git_sha' => $facts->string('git_sha'),
            'composer_lock_hash' => $this->composerLockHash(),
            'app_env' => $this->app->environment(),
            'branch' => $facts->string('branch'),
            'runtimes' => $runtimes->collect($facts),
            'deployed_at' => $facts->string('deployed_at'),
            'checked_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * The hash of the deployed lock file, which a consumer compares with the one
     * committed in the repository to see whether the deploy is running the
     * dependencies it should be.
     */
    private function composerLockHash(): ?string
    {
        $path = $this->app->basePath('composer.lock');

        return is_file($path) ? 'sha256:'.hash_file('sha256', $path) : null;
    }
}
