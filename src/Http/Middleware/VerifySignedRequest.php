<?php

declare(strict_types=1);

namespace Woweb\VersionEndpoint\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Symfony\Component\HttpFoundation\Response;
use Woweb\VersionEndpoint\Support\SignedRequest;

/**
 * Verifies the ed25519 signature the caller put on the request.
 *
 * The caller signs "timestamp\npath" with its private key; this verifies it with
 * the configured public key, which is not a secret. Only a GET or HEAD request
 * can pass. Every failure ends in the router's own not-found exception rather
 * than a 401, 403 or 405, so an unauthenticated caller cannot tell the endpoint
 * apart from a path that does not exist.
 */
class VerifySignedRequest
{
    public function __construct(private readonly Repository $config) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            $this->notFound($request);
        }

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
            $this->notFound($request);
        }

        return $next($request);
    }

    /**
     * Fail exactly the way an unregistered path fails.
     *
     * Matching the request against an empty route collection makes the router
     * throw the same exception, with the same message, that it throws for a path
     * it does not know. The application's exception handler then renders it like
     * any other missing page, so the status, body and headers all match.
     */
    private function notFound(Request $request): never
    {
        (new RouteCollection)->match($request);

        // match() on an empty collection always throws; this only satisfies the
        // return type should that ever change.
        abort(404);
    }
}
