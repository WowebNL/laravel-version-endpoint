<?php

declare(strict_types=1);

namespace Woweb\VersionEndpoint\Tests;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;

class DisabledEndpointTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('version-endpoint.enabled', false);
    }

    #[Test]
    public function it_registers_no_route_at_all_when_switched_off(): void
    {
        $this->assertFalse(Route::has('version-endpoint'));

        $this->signingKeypair();

        $this->getJson('/__version', $this->signedHeaders())->assertNotFound();
    }

    #[Test]
    public function the_build_command_stays_available_when_the_endpoint_is_off(): void
    {
        $path = $this->tempPath();

        $this->artisan('version-endpoint:build', [
            '--tag' => 'v1.0.0', '--sha' => 'abc', '--branch' => 'main', '--node' => '20.0.0',
            '--path' => $path,
        ])->assertSuccessful();

        $this->assertFileExists($path);
    }
}
