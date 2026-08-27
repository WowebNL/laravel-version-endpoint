<?php

declare(strict_types=1);

namespace Woweb\VersionEndpoint\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * The extra runtime versions reported alongside the application's own version.
 *
 * Everything here is keyed by its endoflife.date slug, so a consumer can decide
 * whether a runtime is still supported without knowing anything about this
 * application. Each lookup is read-only, runs only after the signature has been
 * verified, and is wrapped separately: a runtime that is absent, down or not
 * configured drops out of the payload rather than failing the response, because
 * a 500 would hide the whole version picture behind one outage.
 */
final class RuntimeCollector
{
    /** @param array<string, mixed> $enabled */
    public function __construct(
        private readonly array $enabled,
        private readonly string $osReleasePath,
    ) {}

    /** @return array<string, string> */
    public function collect(DeployFacts $facts): array
    {
        $runtimes = [];

        // Node.js is a build-time toolchain, so it is recorded by the deploy
        // rather than looked up here.
        $node = $facts->string('nodejs');

        if ($node !== null) {
            $runtimes['nodejs'] = $node;
        }

        if ($this->isEnabled('os')) {
            $runtimes += $this->operatingSystem();
        }

        if ($this->isEnabled('database')) {
            $runtimes += $this->database();
        }

        if ($this->isEnabled('cache')) {
            $runtimes += $this->cache();
        }

        return $runtimes;
    }

    /**
     * The bare version number out of whatever the engine reports, so no host,
     * build or packaging detail ends up in the payload. PostgreSQL answers
     * something like "17.6 (Debian 17.6-1.pgdg13+1)", of which 17.6 is kept.
     */
    public static function versionNumber(mixed $raw): ?string
    {
        return is_string($raw) && preg_match('/\d+(\.\d+)*/', $raw, $matches) === 1
            ? $matches[0]
            : null;
    }

    /** @return array<string, string> */
    private function operatingSystem(): array
    {
        if ($this->osReleasePath === '' || ! is_readable($this->osReleasePath)) {
            return [];
        }

        $osRelease = @parse_ini_file($this->osReleasePath) ?: [];

        $id = $osRelease['ID'] ?? null;
        $version = $osRelease['VERSION_ID'] ?? null;

        if (! is_string($id) || $id === '' || ! is_string($version) || $version === '') {
            return [];
        }

        return [$id => $version];
    }

    /**
     * The PostgreSQL server version of the default connection.
     *
     * Only PostgreSQL is reported: it is asked through a configuration statement
     * that touches no table, and its version maps cleanly onto a single support
     * cycle. Any other driver is left out rather than guessed at.
     *
     * @return array<string, string>
     */
    private function database(): array
    {
        try {
            $connection = DB::connection();

            if ($connection->getDriverName() !== 'pgsql') {
                return [];
            }

            $row = (array) $connection->selectOne('SHOW server_version');
            $version = self::versionNumber($row['server_version'] ?? null);

            return $version === null ? [] : ['postgresql' => $version];
        } catch (Throwable) {
            // No usable connection: report no engine rather than a 500.
            return [];
        }
    }

    /**
     * The Redis or Valkey server version of the default connection.
     *
     * Valkey also reports a redis_version, but that is a protocol compatibility
     * number: mapping it onto the Redis support cycles would produce a wrong end
     * of life verdict, so valkey_version wins and decides the slug. The phpredis
     * client returns the section flat, predis nests it under its name.
     *
     * @return array<string, string>
     */
    private function cache(): array
    {
        try {
            $info = Redis::connection()->info('server');
            $info = is_array($info) ? ($info['Server'] ?? $info) : [];
            $info = is_array($info) ? $info : [];

            $valkey = self::versionNumber($info['valkey_version'] ?? null);

            if ($valkey !== null) {
                return ['valkey' => $valkey];
            }

            $redis = self::versionNumber($info['redis_version'] ?? null);

            return $redis === null ? [] : ['redis' => $redis];
        } catch (Throwable) {
            // Same: an unreachable cache yields no redis or valkey runtime.
            return [];
        }
    }

    private function isEnabled(string $key): bool
    {
        return filter_var($this->enabled[$key] ?? true, FILTER_VALIDATE_BOOL);
    }
}
