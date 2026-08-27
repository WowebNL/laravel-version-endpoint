<?php

declare(strict_types=1);

namespace Woweb\VersionEndpoint;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Woweb\VersionEndpoint\Console\BuildVersionFileCommand;
use Woweb\VersionEndpoint\Http\Controllers\VersionController;
use Woweb\VersionEndpoint\Http\Middleware\VerifySignedRequest;

class VersionEndpointServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/version-endpoint.php', 'version-endpoint');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/version-endpoint.php' => $this->app->configPath('version-endpoint.php'),
            ], 'version-endpoint-config');

            $this->commands([BuildVersionFileCommand::class]);
        }

        $this->registerRoute();
    }

    /**
     * Register the endpoint, unless it is switched off or has no path.
     *
     * The signature check is a middleware rather than a controller concern, so a
     * request without a valid signature never reaches the runtime lookups.
     */
    protected function registerRoute(): void
    {
        /** @var Repository $config */
        $config = $this->app->make('config');

        if (! $config->get('version-endpoint.enabled', true)) {
            return;
        }

        $path = trim((string) $config->get('version-endpoint.path'), '/');

        if ($path === '') {
            return;
        }

        $route = Route::middleware($this->middleware($config))->get($path, VersionController::class);

        $name = (string) $config->get('version-endpoint.route_name');

        if ($name !== '') {
            $route->name($name);
        }
    }

    /**
     * Throttle first, then the signature check, then anything the application
     * added of its own.
     *
     * @return list<string>
     */
    protected function middleware(Repository $config): array
    {
        $middleware = [];

        $throttle = $config->get('version-endpoint.throttle');

        if (is_string($throttle) && trim($throttle) !== '') {
            $middleware[] = 'throttle:'.trim($throttle);
        }

        $middleware[] = VerifySignedRequest::class;

        foreach ((array) $config->get('version-endpoint.middleware', []) as $extra) {
            if (is_string($extra) && $extra !== '') {
                $middleware[] = $extra;
            }
        }

        return $middleware;
    }
}
