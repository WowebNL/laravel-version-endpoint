<?php

declare(strict_types=1);

namespace Woweb\VersionEndpoint\Support;

/**
 * The signing contract shared by the caller and this application.
 *
 * The message is the request timestamp and the request path, separated by a
 * newline. Signing it rather than the body means no secret travels over the
 * wire, the public key on this side is not worth stealing, and a captured
 * request cannot be replayed once its timestamp falls outside the skew window.
 *
 * Changing the message format is a breaking change to the contract.
 */
final class SignedRequest
{
    public function __construct(
        private readonly string $publicKey,
        private readonly int $clockSkew,
    ) {}

    /** The canonical message both sides sign and verify. */
    public static function message(string $timestamp, string $path): string
    {
        return $timestamp."\n".$path;
    }

    /**
     * Whether the given headers carry a valid, fresh signature for this path.
     *
     * Every failure mode returns false instead of throwing. The length checks
     * matter: base64_decode('', true) returns an empty string rather than false,
     * and sodium_crypto_sign_verify_detached() throws on a signature that is not
     * exactly SODIUM_CRYPTO_SIGN_BYTES long, which would turn an unsigned request
     * into a 500 and give away that the endpoint exists.
     */
    public function verify(?string $timestamp, ?string $signature, string $path): bool
    {
        $publicKey = base64_decode($this->publicKey, true);

        if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        if ($timestamp === null || trim($timestamp) === '' || $signature === null) {
            return false;
        }

        $decodedSignature = base64_decode($signature, true);

        if ($decodedSignature === false || strlen($decodedSignature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > $this->clockSkew) {
            return false;
        }

        return sodium_crypto_sign_verify_detached(
            $decodedSignature,
            self::message($timestamp, $path),
            $publicKey,
        );
    }
}
