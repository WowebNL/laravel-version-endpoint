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

        // Registered for every method the router knows, so no method can tell the
        // path apart from one that does not exist by answering 405, or 200 with an
        // Allow header on OPTIONS. The middleware turns everything but a signed
        // GET or HEAD into the router's own 404.
        $route = Route::middleware($this->middleware($config))->any($path, VersionController::class);

        $name = (string) $config->get('version-endpoint.route_name');

        if ($name !== '') {
            $route->name($name);
        }
    }

    /**
     * The signature check first, then the throttle, then anything the
     * application added of its own.
     *
     * Verifying first keeps unsigned traffic out of the rate limiter entirely:
     * it never touches the cache store, never receives rate limit headers or a
     * 429, and cannot use up the allowance of the caller that does sign. The
     * check itself does no I/O, so it is cheaper than the limiter it now
     * precedes.
     *
     * @return list<string>
     */
    protected function middleware(Repository $config): array
    {
        $middleware = [VerifySignedRequest::class];

        $throttle = $config->get('version-endpoint.throttle');

        if (is_string($throttle) && trim($throttle) !== '') {
            $middleware[] = 'throttle:'.trim($throttle);
        }

        foreach ((array) $config->get('version-endpoint.middleware', []) as $extra) {
            if (is_string($extra) && $extra !== '') {
                $middleware[] = $extra;
            }
        }

        return $middleware;
    }
}
