<?php

declare(strict_types=1);

namespace Woweb\VersionEndpoint\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Woweb\VersionEndpoint\Support\SignedRequest;

/**
 * Verifies the ed25519 signature the caller put on the request.
 *
 * The caller signs "timestamp\npath" with its private key; this verifies it with
 * the configured public key, which is not a secret. Every failure aborts with a
 * 404 rather than a 401 or 403, so an unauthenticated caller cannot tell the
 * endpoint apart from a path that does not exist.
 */
class VerifySignedRequest
{
    public function __construct(private readonly Repository $config) {}

    public function handle(Request $request, Closure $next): Response
    {
        $verifier = new SignedRequest(
            publicKey: (string) $this->config->get('version-endpoint.verify_key'),
            clockSkew: (int) $this->config->get('version-endpoint.clock_skew'),
        );

        $verified = $verifier->verify(
            timestamp: $request->header((string) $this->config->get('version-endpoint.timestamp_header')),
            signature: $request->header((string) $this->config->get('version-endpoint.signature_header')),
            path: $request->getPathInfo(),
        );

        if (! $verified) {
            abort(404);
        }

        return $next($request);
    }
}
