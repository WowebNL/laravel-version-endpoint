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
    public function it_verifies_before_it_rate_limits(): void
    {
        $this->signingKeypair();

        // Unsigned requests stay plain 404s however many arrive, and they leave
        // the allowance of the signed caller untouched.
        $this->getJson('/__version')->assertNotFound()->assertHeaderMissing('X-RateLimit-Limit');
        $this->getJson('/__version')->assertNotFound();
        $this->getJson('/__version')->assertNotFound();

        $this->getJson('/__version', $this->signedHeaders())->assertOk();
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
