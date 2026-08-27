<?php

declare(strict_types=1);

namespace Woweb\VersionEndpoint\Support;

/**
 * The deploy-time facts, read from the file the build command writes.
 *
 * A missing or unreadable file is not an error: the endpoint then reports null
 * for every git field, which is exactly what a checkout that never ran a deploy
 * should say.
 */
final class DeployFacts
{
    /** @param array<string, mixed> $facts */
    private function __construct(private readonly array $facts) {}

    public static function fromFile(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            return new self([]);
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return new self([]);
        }

        $decoded = json_decode($contents, true);

        return new self(is_array($decoded) ? $decoded : []);
    }

    /** A non-empty string value, or null. */
    public function string(string $key): ?string
    {
        $value = $this->facts[$key] ?? null;

        if (is_string($value)) {
            return $value === '' ? null : $value;
        }

        return null;
    }
}
