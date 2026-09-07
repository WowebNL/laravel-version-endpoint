# Laravel version endpoint

A signed, read-only `/__version` endpoint that reports what a Laravel application is actually running: the PHP version, the framework version, the deployed git tag and commit, the hash of the deployed `composer.lock`, and the versions of the runtimes around it.

It exists for inventory tooling. Something has to answer the question "which version of this application is live, and is anything underneath it out of support" without a person logging in to look. This package answers that question over HTTP, to one caller, and to nobody else.

## How it is protected

The caller signs a short message with an ed25519 private key and sends it in two headers. The application verifies that signature with the matching public key.

That direction matters. The public key sits in the application's environment and is not a secret, so an application that is compromised leaks nothing that lets anyone impersonate the caller or read any other application. The signed message is the request timestamp plus the request path, so a captured request cannot be replayed once its timestamp falls outside the tolerance window, and it cannot be pointed at a different path.

Every failure, including a missing key, answers `404`. Without a valid signature the endpoint is indistinguishable from a path that does not exist.

## Requirements

PHP 8.2 or newer with `ext-sodium`, and Laravel 12 or 13.

## Installation

```bash
composer require woweb/laravel-version-endpoint
```

The service provider is auto-discovered and registers the route. Publish the config only if you want to change something:

```bash
php artisan vendor:publish --tag=version-endpoint-config
```

### 1. Generate a keypair

On the machine that will do the probing:

```php
$pair = sodium_crypto_sign_keypair();

echo 'private: '.base64_encode(sodium_crypto_sign_secretkey($pair)).PHP_EOL;
echo 'public:  '.base64_encode(sodium_crypto_sign_publickey($pair)).PHP_EOL;
```

Keep the private key on the caller. One keypair can serve every application, or you can issue one per application; the package does not care which you choose.

### 2. Configure the application

```dotenv
REGISTER_VERIFY_KEY=<the base64 public key>
```

Optional:

```dotenv
REGISTER_CLOCK_SKEW=60
REGISTER_VERSION_FILE=storage/app/private/version.json
VERSION_ENDPOINT_PATH=__version
VERSION_ENDPOINT_THROTTLE=10,1
VERSION_ENDPOINT_ENABLED=true
```

`REGISTER_VERSION_FILE` is resolved against the application base path when it is relative, and used as given when it is absolute. Keep it outside `public/`.

### 3. Write the deploy facts on every deploy

```bash
php artisan version-endpoint:build --tag="$RELEASE_TAG"
```

The command records the tag, commit, branch, Node.js version and deploy timestamp in the version file. The sha, branch and Node.js version are read from the checkout when they are not passed. The endpoint reads that file instead of shelling out to git per request, so a web request never starts a subprocess.

There is no fallback. If a deploy does not run this command, the endpoint reports `null` for the git fields, which is honest: nothing knows what was deployed.

The command also answers to `register:build-version`, so an application that already had a command by that name can adopt the package without touching its deploy pipeline in the same release.

### 4. Sign the request on the caller

```php
$timestamp = (string) time();
$path = '/__version';
$signature = base64_encode(
    sodium_crypto_sign_detached($timestamp."\n".$path, $privateKey)
);

$response = Http::withHeaders([
    'X-Register-Timestamp' => $timestamp,
    'X-Register-Signature' => $signature,
])->get('https://example.test'.$path);
```

The path in the signed message is the path the application sees. A proxy that rewrites the path breaks verification, which shows up as an unexplained `404` after a deploy.

## The response

```json
{
    "php": "8.4.3",
    "framework": "13.29.0",
    "git_tag": "v1.4.0",
    "git_sha": "0c8a1f5e2b...",
    "composer_lock_hash": "sha256:9f2c...",
    "app_env": "production",
    "branch": null,
    "runtimes": {
        "nodejs": "22.9.0",
        "debian": "13",
        "postgresql": "17.6",
        "valkey": "8.1.1"
    },
    "deployed_at": "2026-08-20T19:04:11+00:00",
    "checked_at": "2026-08-27T04:00:02+00:00"
}
```

| Field | Meaning |
| --- | --- |
| `php` | The PHP version serving the request. |
| `framework` | The Laravel version the application booted. |
| `git_tag` | The release tag recorded at deploy time, or `null`. |
| `git_sha` | The commit recorded at deploy time, or `null`. |
| `composer_lock_hash` | `sha256:` plus the hash of the deployed `composer.lock`, so a caller can compare it with the one committed in the repository. |
| `app_env` | The application environment. |
| `branch` | The branch recorded at deploy time. A deploy pinned to a tag is detached and reports `null`. |
| `runtimes` | A map of runtime versions, keyed by their [endoflife.date](https://endoflife.date) slug. Anything not detected is left out. |
| `deployed_at` | When the deploy wrote the version file, or `null`. |
| `checked_at` | When this response was built. |

`runtimes` can carry:

- `nodejs`, from the deploy;
- the distribution id and version from the os-release file, for example `debian` or `alpine`;
- `postgresql`, when the default database connection runs on PostgreSQL;
- `redis` or `valkey`, from the default Redis connection.

Valkey reports a `redis_version` as well, but that is a protocol compatibility number: reporting it as Redis would produce a wrong end of life verdict, so the Valkey version wins and decides the slug.

Every runtime lookup runs only after the signature has been verified, reads no application data, and is wrapped on its own. An engine that is down, absent or not configured drops out of the payload rather than failing the response: a 500 would hide the whole version picture behind a single outage. Switch individual lookups off in the config if you would rather not have them at all.

## The contract

The response shape is the point of this package, so it is versioned with the package itself.

- A **patch** release changes behaviour that is not visible in the response.
- A **minor** release may add a field to the response, or a key to `runtimes`. Consumers must ignore fields they do not know.
- A **major** release removes or renames a field, changes the type of one, or changes the signed message format.

Consumers should read the package version from the application's `composer.lock` to know which contract they are talking to.

## What it deliberately does not do

- It does not report host names, credentials, configuration values, schema or extension lists. Only version metadata.
- It does not run `git` or any other subprocess while serving a request.
- It does not sign the response. The caller authenticates itself to the application, not the other way around; a caller that needs the channel authenticated should use TLS, which it needs anyway.
- It does not detect MySQL or MariaDB. Their version strings do not map onto one support cycle without guessing, and a wrong end of life verdict is worse than a missing one.

## Configuration reference

Everything lives in `config/version-endpoint.php`:

| Key | Default | Purpose |
| --- | --- | --- |
| `enabled` | `true` | Register the route at all. |
| `path` | `__version` | Where the endpoint lives. Part of the signed message. |
| `route_name` | `version-endpoint` | The route name, for `route()` and tests. |
| `throttle` | `10,1` | Laravel's `max,minutes` form, applied before verification. `null` leaves rate limiting to whatever sits in front. |
| `middleware` | `[]` | Extra middleware, applied after verification. |
| `verify_key` | `null` | The base64 ed25519 public key. Without it the endpoint answers 404. |
| `clock_skew` | `60` | Seconds of tolerance on the request timestamp. |
| `timestamp_header` | `X-Register-Timestamp` | |
| `signature_header` | `X-Register-Signature` | |
| `version_file` | `storage/app/private/version.json` | Where the deploy facts are written and read. |
| `file_permissions` | `null` | Octal mode to set on the version file after writing it, for example `0640`. `null` leaves it to the umask, which is safer by default: a mode that excludes the web server user would leave the endpoint unable to read its own facts. |
| `runtimes` | all enabled | Switch the os, database and cache lookups on or off. |
| `os_release_path` | `/etc/os-release` | Where the distribution id and version are read. |

The route is registered outside any middleware group, so it carries no session or cookie handling.

## Development

```bash
composer install
composer test      # PHPUnit, through Orchestra Testbench
composer lint      # Pint
composer analyse   # PHPStan
```

## License

MIT. See [LICENSE.md](LICENSE.md).
