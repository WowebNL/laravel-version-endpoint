<?php

declare(strict_types=1);

namespace Woweb\VersionEndpoint\Tests;

use PHPUnit\Framework\Attributes\Test;

class ThrottleTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('version-endpoint.throttle', '2,1');
    }

    #[Test]
    public function it_rate_limits_before_it_verifies_anything(): void
    {
        $this->signingKeypair();

        // Unsigned requests are 404s, but they still consume the allowance, so a
        // caller cannot probe the endpoint at will.
        $this->getJson('/__version')->assertNotFound();
        $this->getJson('/__version')->assertNotFound();
        $this->getJson('/__version')->assertStatus(429);
    }

    #[Test]
    public function it_lets_a_signed_request_through_within_the_allowance(): void
    {
        $this->signingKeypair();

        $this->getJson('/__version', $this->signedHeaders())->assertOk();
        $this->getJson('/__version', $this->signedHeaders())->assertOk();
        $this->getJson('/__version', $this->signedHeaders())->assertStatus(429);
    }
}
