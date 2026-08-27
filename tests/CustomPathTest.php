<?php

declare(strict_types=1);

namespace Woweb\VersionEndpoint\Tests;

use PHPUnit\Framework\Attributes\Test;

/**
 * The path is configurable, and it is part of the signed message, so a caller
 * has to sign the path it actually requests.
 */
class CustomPathTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('version-endpoint.path', 'internal/version');
    }

    #[Test]
    public function it_serves_the_endpoint_on_the_configured_path(): void
    {
        $secret = $this->signingKeypair();

        $this->getJson('/internal/version', $this->signedHeaders($secret, path: '/internal/version'))
            ->assertOk()
            ->assertJsonStructure(['php', 'framework', 'runtimes']);
    }

    #[Test]
    public function it_no_longer_answers_on_the_default_path(): void
    {
        $this->signingKeypair();

        $this->getJson('/__version', $this->signedHeaders())->assertNotFound();
    }

    #[Test]
    public function it_404s_when_the_signature_covers_the_old_path(): void
    {
        $secret = $this->signingKeypair();

        $this->getJson('/internal/version', $this->signedHeaders($secret, path: '/__version'))
            ->assertNotFound();
    }
}
