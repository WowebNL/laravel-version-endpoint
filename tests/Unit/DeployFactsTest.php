<?php

declare(strict_types=1);

namespace Woweb\VersionEndpoint\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Woweb\VersionEndpoint\Support\DeployFacts;

class DeployFactsTest extends TestCase
{
    #[Test]
    public function it_reads_string_facts_and_ignores_everything_else(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'facts');
        file_put_contents($path, json_encode([
            'git_tag' => 'v1.0.0',
            'git_sha' => '',
            'branch' => null,
            'deployed_at' => 12345,
        ]));

        $facts = DeployFacts::fromFile($path);

        $this->assertSame('v1.0.0', $facts->string('git_tag'));
        $this->assertNull($facts->string('git_sha'));
        $this->assertNull($facts->string('branch'));
        $this->assertNull($facts->string('deployed_at'));
        $this->assertNull($facts->string('absent'));

        @unlink($path);
    }

    #[Test]
    public function a_missing_file_yields_no_facts_rather_than_an_error(): void
    {
        $facts = DeployFacts::fromFile(sys_get_temp_dir().'/definitely-not-here-'.bin2hex(random_bytes(6)).'.json');

        $this->assertNull($facts->string('git_tag'));
    }
}
