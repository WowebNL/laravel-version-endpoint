# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package follows
[semantic versioning](https://semver.org/spec/v2.0.0.html). The response shape is
part of the public contract: see "The contract" in the README for what counts as
a patch, a minor and a major release.

## [Unreleased]

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
