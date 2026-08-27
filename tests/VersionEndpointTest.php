<?php

declare(strict_types=1);

namespace Woweb\VersionEndpoint\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class VersionEndpointTest extends TestCase
{
    #[Test]
    public function it_answers_a_validly_signed_request_with_the_full_payload(): void
    {
        $this->signingKeypair();

        $this->getJson('/__version', $this->signedHeaders())
            ->assertOk()
            ->assertJson([
                'php' => PHP_VERSION,
                'app_env' => 'testing',
            ])
            ->assertJsonStructure([
                'php', 'framework', 'git_tag', 'git_sha', 'composer_lock_hash',
                'app_env', 'branch', 'runtimes', 'deployed_at', 'checked_at',
            ]);
    }

    #[Test]
    public function it_reports_the_payload_fields_in_the_published_order(): void
    {
        $this->signingKeypair();

        $response = $this->getJson('/__version', $this->signedHeaders())->assertOk();

        $this->assertSame([
            'php', 'framework', 'git_tag', 'git_sha', 'composer_lock_hash',
            'app_env', 'branch', 'runtimes', 'deployed_at', 'checked_at',
        ], array_keys($response->json()));
    }

    #[Test]
    public function it_exposes_the_deploy_time_facts_from_the_version_file(): void
    {
        $this->signingKeypair();
        $this->useVersionFile([
            'git_tag' => 'v1.2.3',
            'git_sha' => 'abc123def456',
            'branch' => null,
            'nodejs' => '22.1.0',
            'deployed_at' => '2026-07-08T10:00:00+00:00',
        ]);

        $this->getJson('/__version', $this->signedHeaders())
            ->assertOk()
            ->assertJson([
                'git_tag' => 'v1.2.3',
                'git_sha' => 'abc123def456',
                'branch' => null,
                'deployed_at' => '2026-07-08T10:00:00+00:00',
            ])
            ->assertJsonPath('runtimes.nodejs', '22.1.0');
    }

    #[Test]
    public function it_reports_null_git_fields_when_the_version_file_is_missing(): void
    {
        $this->signingKeypair();
        config(['version-endpoint.version_file' => $this->tempPath()]);

        $this->getJson('/__version', $this->signedHeaders())
            ->assertOk()
            ->assertJson(['git_tag' => null, 'git_sha' => null, 'branch' => null, 'deployed_at' => null]);
    }

    #[Test]
    public function it_reports_null_git_fields_when_the_version_file_is_not_json(): void
    {
        $this->signingKeypair();
        $path = $this->tempPath();
        file_put_contents($path, 'not json at all');
        config(['version-endpoint.version_file' => $path]);

        $this->getJson('/__version', $this->signedHeaders())
            ->assertOk()
            ->assertJson(['git_tag' => null, 'git_sha' => null]);
    }

    #[Test]
    public function it_reports_the_composer_lock_hash_of_the_deployed_application(): void
    {
        $this->signingKeypair();

        $lock = $this->app->basePath('composer.lock');
        $expected = is_file($lock) ? 'sha256:'.hash_file('sha256', $lock) : null;

        $this->getJson('/__version', $this->signedHeaders())
            ->assertOk()
            ->assertJsonPath('composer_lock_hash', $expected);
    }

    #[Test]
    public function it_reports_the_operating_system_from_the_os_release_file(): void
    {
        $this->signingKeypair();

        $path = $this->tempPath('.osrelease');
        file_put_contents($path, "ID=debian\nVERSION_ID=\"13\"\nPRETTY_NAME=\"Example\"\n");
        config(['version-endpoint.os_release_path' => $path]);

        $this->getJson('/__version', $this->signedHeaders())
            ->assertOk()
            ->assertJsonPath('runtimes.debian', '13');
    }

    #[Test]
    public function it_omits_the_operating_system_when_the_os_release_file_is_absent(): void
    {
        $this->signingKeypair();
        $this->withoutOsRelease();
        $this->unreachableDatabase();
        $this->unreachableCache();

        $this->getJson('/__version', $this->signedHeaders())
            ->assertOk()
            ->assertJsonPath('runtimes', []);
    }

    #[Test]
    public function it_reports_the_postgresql_and_redis_versions_when_both_engines_answer(): void
    {
        $this->signingKeypair();
        $this->withoutOsRelease();
        // The packaged suffix is what a managed server actually returns.
        $this->fakePostgres('17.6 (Debian 17.6-1.pgdg13+1)');
        $this->fakeRedisInfo(['redis_version' => '7.4.2', 'redis_mode' => 'standalone']);

        $this->getJson('/__version', $this->signedHeaders())
            ->assertOk()
            ->assertJsonPath('runtimes.postgresql', '17.6')
            ->assertJsonPath('runtimes.redis', '7.4.2');
    }

    #[Test]
    public function it_reports_valkey_on_its_own_slug_instead_of_its_redis_compatibility_version(): void
    {
        $this->signingKeypair();
        $this->withoutOsRelease();
        $this->unreachableDatabase();
        $this->fakeRedisInfo(['redis_version' => '7.2.4', 'valkey_version' => '8.1.1']);

        $this->getJson('/__version', $this->signedHeaders())
            ->assertOk()
            ->assertJsonPath('runtimes.valkey', '8.1.1')
            ->assertJsonMissingPath('runtimes.redis');
    }

    #[Test]
    public function it_reads_the_nested_info_response_as_well_as_the_flat_one(): void
    {
        $this->signingKeypair();
        $this->withoutOsRelease();
        $this->unreachableDatabase();
        // One client nests the section under its name, the other returns it flat.
        $this->fakeRedisInfo(['Server' => ['redis_version' => '8.0.3']]);

        $this->getJson('/__version', $this->signedHeaders())
            ->assertOk()
            ->assertJsonPath('runtimes.redis', '8.0.3');
    }

    #[Test]
    public function it_does_not_query_the_database_when_the_default_connection_is_not_postgresql(): void
    {
        $this->signingKeypair();
        $this->withoutOsRelease();
        $this->unreachableCache();

        $connection = Mockery::mock();
        $connection->shouldReceive('getDriverName')->andReturn('sqlite');
        $connection->shouldNotReceive('selectOne');
        DB::shouldReceive('connection')->andReturn($connection);

        $this->getJson('/__version', $this->signedHeaders())
            ->assertOk()
            ->assertJsonMissingPath('runtimes.postgresql');
    }

    #[Test]
    public function it_answers_without_engine_runtimes_when_no_engine_is_reachable(): void
    {
        $this->signingKeypair();
        $this->withoutOsRelease();
        $this->useVersionFile(['git_tag' => 'v1.2.3', 'nodejs' => '22.1.0']);
        $this->unreachableDatabase();
        $this->unreachableCache();

        $this->getJson('/__version', $this->signedHeaders())
            ->assertOk()
            ->assertJson(['git_tag' => 'v1.2.3'])
            ->assertJsonPath('runtimes.nodejs', '22.1.0')
            ->assertJsonMissingPath('runtimes.postgresql')
            ->assertJsonMissingPath('runtimes.redis')
            ->assertJsonMissingPath('runtimes.valkey');
    }

    #[Test]
    public function it_skips_the_engine_lookups_when_they_are_switched_off(): void
    {
        $this->signingKeypair();
        $this->withoutOsRelease();
        config(['version-endpoint.runtimes' => ['os' => false, 'database' => false, 'cache' => false]]);

        DB::shouldReceive('connection')->never();
        Redis::shouldReceive('connection')->never();

        $this->getJson('/__version', $this->signedHeaders())
            ->assertOk()
            ->assertJsonPath('runtimes', []);
    }

    #[Test]
    public function it_404s_without_a_signature_so_the_route_is_invisible(): void
    {
        $this->signingKeypair();

        $this->getJson('/__version')->assertNotFound();
    }

    #[Test]
    public function it_404s_on_an_invalid_signature(): void
    {
        $this->signingKeypair();

        $this->getJson('/__version', [
            'X-Register-Timestamp' => (string) time(),
            'X-Register-Signature' => base64_encode(str_repeat("\0", SODIUM_CRYPTO_SIGN_BYTES)),
        ])->assertNotFound();
    }

    #[Test]
    public function it_404s_on_a_malformed_signature_without_throwing(): void
    {
        $this->signingKeypair();
        $timestamp = (string) time();

        // An empty header decodes to an empty string rather than false, so the
        // length guard has to catch it before verification throws.
        $this->getJson('/__version', ['X-Register-Timestamp' => $timestamp])->assertNotFound();

        $this->getJson('/__version', [
            'X-Register-Timestamp' => $timestamp,
            'X-Register-Signature' => base64_encode('too-short'),
        ])->assertNotFound();

        $this->getJson('/__version', [
            'X-Register-Timestamp' => $timestamp,
            'X-Register-Signature' => '!!!not-base64!!!',
        ])->assertNotFound();
    }

    #[Test]
    public function it_404s_on_a_signature_made_for_another_path(): void
    {
        $secret = $this->signingKeypair();

        $this->getJson('/__version', $this->signedHeaders($secret, path: '/__something-else'))
            ->assertNotFound();
    }

    #[Test]
    public function it_404s_on_a_stale_or_future_timestamp(): void
    {
        $secret = $this->signingKeypair();

        $this->getJson('/__version', $this->signedHeaders($secret, time() - 3600))->assertNotFound();
        $this->getJson('/__version', $this->signedHeaders($secret, time() + 3600))->assertNotFound();
    }

    #[Test]
    public function it_404s_when_no_verify_key_is_configured(): void
    {
        $secret = $this->signingKeypair();
        config(['version-endpoint.verify_key' => null]);

        $this->getJson('/__version', $this->signedHeaders($secret))->assertNotFound();
    }

    #[Test]
    public function it_404s_when_the_verify_key_is_not_a_usable_public_key(): void
    {
        $secret = $this->signingKeypair();
        config(['version-endpoint.verify_key' => base64_encode('far too short')]);

        $this->getJson('/__version', $this->signedHeaders($secret))->assertNotFound();
    }

    /** Let the default connection answer the version query like a real server. */
    private function fakePostgres(string $serverVersion): void
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('getDriverName')->andReturn('pgsql');
        $connection->shouldReceive('selectOne')->with('SHOW server_version')
            ->andReturn((object) ['server_version' => $serverVersion]);

        DB::shouldReceive('connection')->andReturn($connection);
    }

    /** Let the cache answer with the given server section. */
    private function fakeRedisInfo(array $info): void
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('info')->with('server')->andReturn($info);

        Redis::shouldReceive('connection')->andReturn($connection);
    }

    /** No database at all, as on a host where the engine is down or absent. */
    private function unreachableDatabase(): void
    {
        DB::shouldReceive('connection')->andThrow(new RuntimeException('no database'));
    }

    /** Same for the cache: connecting throws instead of returning a client. */
    private function unreachableCache(): void
    {
        Redis::shouldReceive('connection')->andThrow(new RuntimeException('no cache'));
    }

    /**
     * Point the os-release lookup at a path that does not exist, so a test that
     * is about the engines is not affected by the host it runs on.
     */
    private function withoutOsRelease(): void
    {
        config(['version-endpoint.os_release_path' => $this->tempPath('.osrelease')]);
    }
}
