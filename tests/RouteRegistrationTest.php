<?php

declare(strict_types=1);

namespace Woweb\VersionEndpoint\Tests;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Woweb\VersionEndpoint\Http\Middleware\VerifySignedRequest;

class RouteRegistrationTest extends TestCase
{
    #[Test]
    public function it_registers_the_endpoint_under_a_stable_route_name(): void
    {
        $this->assertTrue(Route::has('version-endpoint'));
        $this->assertSame('__version', Route::getRoutes()->getByName('version-endpoint')->uri());
    }

    #[Test]
    public function it_publishes_a_config_file_the_application_can_override(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'version-endpoint-config'])->assertSuccessful();

        $published = $this->app->configPath('version-endpoint.php');

        $this->assertFileExists($published);
        @unlink($published);
    }

    #[Test]
    public function it_verifies_the_signature_before_anything_else_runs(): void
    {
        $middleware = Route::getRoutes()->getByName('version-endpoint')->gatherMiddleware();

        $this->assertContains('throttle:10,1', $middleware);
        $this->assertContains(VerifySignedRequest::class, $middleware);
        $this->assertLessThan(
            array_search(VerifySignedRequest::class, $middleware, true),
            array_search('throttle:10,1', $middleware, true),
        );
    }
}
