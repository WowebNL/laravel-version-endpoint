<?php

declare(strict_types=1);

namespace Woweb\VersionEndpoint\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Woweb\VersionEndpoint\Support\VersionFilePath;

class VersionFilePathTest extends TestCase
{
    #[Test]
    public function it_resolves_a_relative_path_against_the_base_path(): void
    {
        $this->assertSame(
            '/srv/app/storage/app/private/version.json',
            VersionFilePath::resolve('storage/app/private/version.json', '/srv/app'),
        );
    }

    #[Test]
    public function it_does_not_double_the_separator(): void
    {
        $this->assertSame(
            '/srv/app/storage/version.json',
            VersionFilePath::resolve('storage/version.json', '/srv/app/'),
        );
    }

    #[Test]
    public function a_leading_slash_makes_the_path_absolute(): void
    {
        $this->assertSame(
            '/storage/version.json',
            VersionFilePath::resolve('/storage/version.json', '/srv/app'),
        );
    }

    #[Test]
    public function it_leaves_an_absolute_path_alone(): void
    {
        $this->assertSame(
            '/var/lib/shared/version.json',
            VersionFilePath::resolve('/var/lib/shared/version.json', '/srv/app'),
        );
    }

    #[Test]
    public function it_returns_an_empty_string_when_nothing_is_configured(): void
    {
        $this->assertSame('', VersionFilePath::resolve('   ', '/srv/app'));
    }
}
