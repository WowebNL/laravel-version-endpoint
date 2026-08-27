<?php

declare(strict_types=1);

namespace Woweb\VersionEndpoint\Support;

/**
 * Resolves the configured version file location.
 *
 * A relative path is taken from the application base path, so the default works
 * everywhere; an absolute path is used as configured, for applications that keep
 * the file on a shared or release-specific volume.
 */
final class VersionFilePath
{
    public static function resolve(string $configured, string $basePath): string
    {
        $configured = trim($configured);

        if ($configured === '') {
            return '';
        }

        if (self::isAbsolute($configured)) {
            return $configured;
        }

        return rtrim($basePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($configured, DIRECTORY_SEPARATOR);
    }

    private static function isAbsolute(string $path): bool
    {
        // A leading slash covers POSIX, and a drive letter or UNC prefix covers
        // Windows, where the package may still be developed against.
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }
}
