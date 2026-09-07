# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package follows
[semantic versioning](https://semver.org/spec/v2.0.0.html). The response shape is
part of the public contract: see "The contract" in the README for what counts as
a patch, a minor and a major release.

## [Unreleased]

## [0.2.0] - 2026-09-07

### Changed

- Dropped support for Laravel 11. Its security support ended on 12 March 2026 and
  every release in that range carries published advisories, so Composer refuses to
  install it and the test matrix could not resolve dependencies. The package now
  requires `^12.0 || ^13.0` for the framework and `^10.0 || ^11.0` for the test
  harness. Stay on 0.1.0 if you are still on Laravel 11.
- Reduced the test matrix from ten job combinations to seven, with the
  lowest-dependency run on the oldest supported release so the declared minimums
  stay real.

### Security

- Added a security policy describing how to report a vulnerability.
- Pinned all GitHub Actions to commit SHAs instead of moving tags. One of the
  three was pinned to a branch rather than a tag.
- Added `.env` files to `.gitignore`. None were committed; this is preventive.

## [0.1.0] - 2026-08-31

### Added

- Signed `/__version` endpoint, registered by the service provider on a
  configurable path and protected by ed25519 request verification.
- `version-endpoint:build` command that records the deploy-time git tag, commit,
  branch, Node.js version and deploy timestamp, aliased as
  `register:build-version`.
- Runtime reporting for the operating system, PostgreSQL, and Redis or Valkey,
  each switchable and each failing quietly rather than failing the response.
- Rate limiting in front of the signature check, publishable configuration, and a
  test suite covering the payload, the contract field order and every rejection
  path.
