<?php

declare(strict_types=1);

namespace Woweb\VersionEndpoint\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Woweb\VersionEndpoint\Support\SignedRequest;
use Woweb\VersionEndpoint\VersionEndpointServiceProvider;

abstract class TestCase extends Orchestra
{
    /** The secret key matching the public key put in config by signingKeypair(). */
    protected string $secretKey = '';

    protected function getPackageProviders($app): array
    {
        return [VersionEndpointServiceProvider::class];
    }

    /**
     * Put a fresh public key in config and keep the matching secret key, so a
     * test can sign requests the way a real caller would.
     */
    protected function signingKeypair(): string
    {
        $pair = sodium_crypto_sign_keypair();

        config(['version-endpoint.verify_key' => base64_encode(sodium_crypto_sign_publickey($pair))]);

        return $this->secretKey = sodium_crypto_sign_secretkey($pair);
    }

    /**
     * The headers a caller sends for a signed request.
     *
     * @return array<string, string>
     */
    protected function signedHeaders(?string $secret = null, ?int $timestamp = null, string $path = '/__version'): array
    {
        $secret ??= $this->secretKey;
        $timestamp ??= time();

        return [
            'X-Register-Timestamp' => (string) $timestamp,
            'X-Register-Signature' => base64_encode(
                sodium_crypto_sign_detached(SignedRequest::message((string) $timestamp, $path), $secret)
            ),
        ];
    }

    /** A unique temp file path that tearDown() cleans up. */
    protected function tempPath(string $suffix = '.json'): string
    {
        return sys_get_temp_dir().'/version-endpoint-test-'.bin2hex(random_bytes(8)).$suffix;
    }

    /** Point the endpoint at a version file holding the given deploy facts. */
    protected function useVersionFile(array $facts): string
    {
        $path = $this->tempPath();
        file_put_contents($path, json_encode($facts));
        config(['version-endpoint.version_file' => $path]);

        return $path;
    }

    protected function tearDown(): void
    {
        foreach (glob(sys_get_temp_dir().'/version-endpoint-test-*') ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }
}
