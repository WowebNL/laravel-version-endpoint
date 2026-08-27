<?php

declare(strict_types=1);

namespace Woweb\VersionEndpoint\Tests;

use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;

class BuildVersionFileCommandTest extends TestCase
{
    #[Test]
    public function it_writes_the_version_file_from_the_given_options(): void
    {
        $path = $this->tempPath();
        config(['version-endpoint.version_file' => $path]);

        $this->artisan('version-endpoint:build', [
            '--tag' => 'v9.9.9',
            '--sha' => 'deadbeef',
            '--branch' => 'main',
            '--node' => '20.11.0',
        ])->assertSuccessful();

        $data = json_decode((string) file_get_contents($path), true);

        $this->assertSame('v9.9.9', $data['git_tag']);
        $this->assertSame('deadbeef', $data['git_sha']);
        $this->assertSame('main', $data['branch']);
        $this->assertSame('20.11.0', $data['nodejs']);
        $this->assertArrayHasKey('deployed_at', $data);
    }

    #[Test]
    public function it_keeps_answering_to_its_previous_command_name(): void
    {
        // An alias, so an application adopting the package does not have to change
        // its deploy step in the same release.
        $path = $this->tempPath();

        $this->artisan('register:build-version', [
            '--tag' => 'v1.0.0', '--sha' => 'abc', '--branch' => 'main', '--node' => '20.0.0',
            '--path' => $path,
        ])->assertSuccessful();

        $this->assertFileExists($path);
    }

    #[Test]
    public function it_resolves_a_relative_version_file_against_the_application_root(): void
    {
        $relative = 'storage/app/private/'.basename($this->tempPath());
        config(['version-endpoint.version_file' => $relative]);

        $this->artisan('version-endpoint:build', ['--tag' => 'v1.0.0', '--sha' => 'abc', '--branch' => 'main', '--node' => '20.0.0'])
            ->assertSuccessful();

        $absolute = $this->app->basePath($relative);

        $this->assertFileExists($absolute);
        @unlink($absolute);
    }

    #[Test]
    public function it_writes_to_the_path_option_when_it_is_given(): void
    {
        $path = $this->tempPath();
        config(['version-endpoint.version_file' => 'storage/app/private/not-this-one.json']);

        $this->artisan('version-endpoint:build', [
            '--tag' => 'v2.0.0', '--sha' => 'abc', '--branch' => 'main', '--node' => '20.0.0',
            '--path' => $path,
        ])->assertSuccessful();

        $this->assertFileExists($path);
    }

    #[Test]
    public function it_applies_the_configured_file_permissions(): void
    {
        $path = $this->tempPath();
        config([
            'version-endpoint.version_file' => $path,
            'version-endpoint.file_permissions' => 0640,
        ]);

        $this->artisan('version-endpoint:build', ['--tag' => 'v1.0.0', '--sha' => 'abc', '--branch' => 'main', '--node' => '20.0.0'])
            ->assertSuccessful();

        $this->assertSame('0640', substr(sprintf('%o', fileperms($path)), -4));
    }

    #[Test]
    public function it_omits_the_node_version_when_node_is_unavailable(): void
    {
        // No --node option, and the lookup fails, so the field is left out entirely.
        Process::fake(['node*' => Process::result(errorOutput: 'not found', exitCode: 127)]);

        $path = $this->tempPath();
        config(['version-endpoint.version_file' => $path]);

        $this->artisan('version-endpoint:build', [
            '--tag' => 'v9.9.9',
            '--sha' => 'deadbeef',
            '--branch' => 'main',
        ])->assertSuccessful();

        $this->assertArrayNotHasKey('nodejs', json_decode((string) file_get_contents($path), true));
    }

    #[Test]
    public function it_falls_back_to_git_for_the_sha_when_the_option_is_omitted(): void
    {
        Process::fake([
            'git rev-parse HEAD' => Process::result(output: "cafebabe1234\n"),
            'node*' => Process::result(exitCode: 127),
        ]);

        $path = $this->tempPath();
        config(['version-endpoint.version_file' => $path]);

        $this->artisan('version-endpoint:build', [
            '--tag' => 'v9.9.9',
            '--branch' => 'main',
        ])->assertSuccessful();

        $this->assertSame('cafebabe1234', json_decode((string) file_get_contents($path), true)['git_sha']);
    }

    #[Test]
    public function it_reports_no_branch_when_the_checkout_is_detached(): void
    {
        // A deploy pinned to a tag is detached, which git reports as HEAD.
        Process::fake([
            'git rev-parse --abbrev-ref HEAD' => Process::result(output: "HEAD\n"),
            'node*' => Process::result(exitCode: 127),
        ]);

        $path = $this->tempPath();
        config(['version-endpoint.version_file' => $path]);

        $this->artisan('version-endpoint:build', ['--tag' => 'v9.9.9', '--sha' => 'deadbeef'])
            ->assertSuccessful();

        $this->assertNull(json_decode((string) file_get_contents($path), true)['branch']);
    }

    #[Test]
    public function it_leaves_the_git_fields_null_when_git_is_unavailable(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'not found', exitCode: 127)]);

        $path = $this->tempPath();
        config(['version-endpoint.version_file' => $path]);

        $this->artisan('version-endpoint:build')->assertSuccessful();

        $data = json_decode((string) file_get_contents($path), true);

        $this->assertNull($data['git_tag']);
        $this->assertNull($data['git_sha']);
        $this->assertNull($data['branch']);
    }

    #[Test]
    public function the_endpoint_reads_back_exactly_what_the_command_wrote(): void
    {
        $path = $this->tempPath();
        config(['version-endpoint.version_file' => $path]);
        $this->signingKeypair();

        $this->artisan('version-endpoint:build', [
            '--tag' => 'v3.1.4',
            '--sha' => 'facefeed',
            '--branch' => 'release',
            '--node' => '22.9.0',
        ])->assertSuccessful();

        $written = json_decode((string) file_get_contents($path), true);

        $this->getJson('/__version', $this->signedHeaders())
            ->assertOk()
            ->assertJson([
                'git_tag' => 'v3.1.4',
                'git_sha' => 'facefeed',
                'branch' => 'release',
                'deployed_at' => $written['deployed_at'],
            ])
            ->assertJsonPath('runtimes.nodejs', '22.9.0');
    }
}
