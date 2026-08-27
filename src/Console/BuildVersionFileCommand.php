<?php

declare(strict_types=1);

namespace Woweb\VersionEndpoint\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Woweb\VersionEndpoint\Support\VersionFilePath;

/**
 * Writes the deploy-time facts the endpoint reads.
 *
 * Run this as a deploy step. The deploy knows the tag it is releasing, so pass it
 * with --tag; the sha, branch and Node.js version are read from the checkout when
 * they are not given. Keeping git out of the request path means the endpoint
 * never has to run a subprocess while serving a request.
 *
 * Only deploy-time facts live in the file. The PHP version, framework version,
 * environment, lock file hash and engine versions are read per request, because
 * those can change without a deploy.
 */
class BuildVersionFileCommand extends Command
{
    protected $signature = 'version-endpoint:build
        {--tag= : The release tag (defaults to `git describe`)}
        {--sha= : The commit sha (defaults to `git rev-parse HEAD`)}
        {--branch= : The branch (defaults to git; a tag deploy is detached, so null)}
        {--node= : The Node.js version (defaults to `node -v`; omitted if unknown)}
        {--path= : Write to this file instead of the configured one}';

    protected $description = 'Write the deploy-time version file the version endpoint reads';

    /** @var list<string> */
    protected $aliases = ['register:build-version'];

    public function handle(): int
    {
        $data = [
            'git_tag' => $this->stringOption('tag') ?? $this->git('describe --tags --always'),
            'git_sha' => $this->stringOption('sha') ?? $this->git('rev-parse HEAD'),
            'branch' => $this->stringOption('branch') ?? $this->currentBranch(),
            'deployed_at' => now()->toIso8601String(),
        ];

        // Only recorded when it is actually known, so an application without a
        // Node toolchain does not report a runtime it does not have.
        $node = $this->stringOption('node') ?? $this->nodeVersion();

        if ($node !== null) {
            $data['nodejs'] = $node;
        }

        $path = $this->targetPath();

        if ($path === '') {
            $this->error('No version file path configured.');

            return self::FAILURE;
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $this->info("Wrote {$path}");
        $this->line('  '.json_encode($data, JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function targetPath(): string
    {
        $configured = $this->stringOption('path')
            ?? (string) $this->laravel->make('config')->get('version-endpoint.version_file');

        return VersionFilePath::resolve($configured, $this->laravel->basePath());
    }

    /** A non-empty trimmed option value, or null. */
    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Run a git subcommand in the application root; null on any failure or empty
     * output. The arguments are static, so a string command is safe here.
     */
    private function git(string $arguments): ?string
    {
        $result = Process::path($this->laravel->basePath())->run('git '.$arguments);

        if (! $result->successful()) {
            return null;
        }

        $output = trim($result->output());

        return $output !== '' ? $output : null;
    }

    /** The checked out branch, or null when detached: a tag deploy reports HEAD. */
    private function currentBranch(): ?string
    {
        $branch = $this->git('rev-parse --abbrev-ref HEAD');

        return ($branch === null || $branch === 'HEAD') ? null : $branch;
    }

    /** The Node.js version, or null when node is not available. */
    private function nodeVersion(): ?string
    {
        $result = Process::run('node -v');

        if ($result->successful() && preg_match('/(\d+\.\d+\.\d+)/', $result->output(), $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
