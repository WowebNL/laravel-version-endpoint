# Security Policy

This package exposes an HTTP endpoint that reports what a Laravel application is running, so
its purpose depends on that endpoint staying closed to everyone but the intended caller.
Security reports are taken seriously.

## Supported versions

Security fixes are provided for the latest released minor version. Until the first stable
release, fixes land on the default branch.

## Reporting a vulnerability

Please do not open a public issue for a security problem. Report it privately so it can be
fixed before it becomes widely known.

1. Email info@woweb.nl with a description of the issue.
2. Include the affected version or commit, reproduction steps, and the impact you foresee.
3. If you have a proposed fix, you are welcome to attach it.

You can expect an acknowledgement within five working days. Once the issue is confirmed, a fix
and a coordinated disclosure timeline will be agreed with you.

## Scope and design notes

A few security properties are intentional and documented in the README:

1. Requests are authenticated with an ed25519 signature over the request timestamp and the
   request path. Only the public key is stored in the application, so a compromised
   application cannot be used to impersonate the caller or to reach another application.
2. Because the signed message binds a request to both a moment and a path, a captured request
   cannot be reused outside the tolerance window or aimed at a different route.
3. Every failure, including a missing key, answers 404. An unauthenticated caller cannot tell
   the endpoint apart from a route that does not exist.

If you find a way around any of these, that qualifies as a vulnerability worth reporting.
