<?php

declare(strict_types=1);

namespace Woweb\VersionEndpoint\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Woweb\VersionEndpoint\Support\RuntimeCollector;

class RuntimeVersionNumberTest extends TestCase
{
    #[Test]
    public function it_keeps_only_the_bare_version_number(): void
    {
        $this->assertSame('17.6', RuntimeCollector::versionNumber('17.6 (Debian 17.6-1.pgdg13+1)'));
        $this->assertSame('7.4.2', RuntimeCollector::versionNumber('7.4.2'));
        $this->assertSame('11', RuntimeCollector::versionNumber('11'));
    }

    #[Test]
    public function it_returns_null_for_anything_without_a_version_number(): void
    {
        $this->assertNull(RuntimeCollector::versionNumber('unknown'));
        $this->assertNull(RuntimeCollector::versionNumber(null));
        $this->assertNull(RuntimeCollector::versionNumber(['17.6']));
    }
}
