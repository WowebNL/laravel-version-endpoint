<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Route registration
    |--------------------------------------------------------------------------
    |
    | The endpoint is registered by the package's service provider. Disable it
    | entirely where it is not wanted, or move it to another path. The path is
    | part of the signed message, so both sides have to agree on it: a proxy that
    | rewrites the path breaks verification and the endpoint answers 404.
    |
    */

    'enabled' => env('VERSION_ENDPOINT_ENABLED', true),

    'path' => env('VERSION_ENDPOINT_PATH', '__version'),

    'route_name' => 'version-endpoint',

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    |
    | The route is registered outside any middleware group, so it carries no
    | session or cookie handling. "throttle" is applied first, in Laravel's
    | "max,minutes" form; set it to null to leave rate limiting to the stack in
    | front of the application. Anything in "middleware" runs after it.
    |
    */

    'throttle' => env('VERSION_ENDPOINT_THROTTLE', '10,1'),

    'middleware' => [],

    /*
    |--------------------------------------------------------------------------
    | Signature verification
    |--------------------------------------------------------------------------
    |
    | Callers sign a "timestamp\npath" message with their ed25519 private key and
    | send it in two headers. This application verifies it with the matching
    | public key, which is not a secret, and rejects timestamps that drift beyond
    | "clock_skew" seconds so a captured request cannot be replayed later.
    |
    | Every failure answers 404, so without a valid signature the endpoint does
    | not appear to exist at all.
    |
    | Read through config() rather than env() so the endpoint keeps working after
    | `php artisan config:cache`, where env() outside config files returns null.
    |
    */

    'verify_key' => env('REGISTER_VERIFY_KEY'),

    'clock_skew' => (int) env('REGISTER_CLOCK_SKEW', 60),

    'timestamp_header' => 'X-Register-Timestamp',

    'signature_header' => 'X-Register-Signature',

    /*
    |--------------------------------------------------------------------------
    | Deploy-time facts
    |--------------------------------------------------------------------------
    |
    | The git tag, sha, branch, deploy timestamp and Node.js version are written
    | once per deploy by `php artisan version-endpoint:build`, so the request path
    | never shells out to git. A relative path is resolved against the application
    | base path; an absolute path is used as given. Keep it outside public/ so it
    | is never web-served.
    |
    | There is deliberately no fallback: when a deploy does not run the command,
    | the endpoint simply reports null for these fields.
    |
    */

    'version_file' => env('REGISTER_VERSION_FILE', 'storage/app/private/version.json'),

    /*
    |--------------------------------------------------------------------------
    | Runtimes
    |--------------------------------------------------------------------------
    |
    | Extra runtime versions, keyed by their endoflife.date slug, so a consumer
    | can spot an engine that fell out of support without a deploy having
    | happened. Every lookup is read-only and runs only after the signature has
    | been verified; anything that is absent, unreachable or not configured drops
    | out of the payload instead of failing the response.
    |
    | "os" reads the distribution id and version from an os-release file.
    | "database" reports the PostgreSQL server version of the default connection,
    | and does nothing on any other driver. "cache" reports the Redis or Valkey
    | server version of the default Redis connection.
    |
    */

    'runtimes' => [
        'os' => env('VERSION_ENDPOINT_RUNTIME_OS', true),
        'database' => env('VERSION_ENDPOINT_RUNTIME_DATABASE', true),
        'cache' => env('VERSION_ENDPOINT_RUNTIME_CACHE', true),
    ],

    'os_release_path' => '/etc/os-release',

];
