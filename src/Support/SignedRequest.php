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
 * More than one public key can be trusted at the same time, so every caller can
 * hold its own keypair and one can be rotated or revoked without touching the
 * others. A request passes when its signature verifies against any of them.
 *
 * Changing the message format is a breaking change to the contract.
 */
final class SignedRequest
{
    /** The separator between public keys in a single configuration string. */
    public const KEY_SEPARATOR = ',';

    /**
     * @param  string|array<array-key, mixed>|null  $publicKey  One base64 public key, several separated
     *                                                          by commas, or a list of them.
     */
    public function __construct(
        private readonly string|array|null $publicKey,
        private readonly int $clockSkew,
    ) {}

    /** The canonical message both sides sign and verify. */
    public static function message(string $timestamp, string $path): string
    {
        return $timestamp."\n".$path;
    }

    /**
     * The configured public keys as a list of non-empty strings.
     *
     * A string is split on commas, which cannot occur in base64. Every element is
     * trimmed, and empty elements are dropped, so a trailing comma or a stray
     * space does not turn into a key. Anything that is not a string is ignored.
     *
     * @param  string|array<array-key, mixed>|null  $configured
     * @return list<string>
     */
    public static function keys(string|array|null $configured): array
    {
        if ($configured === null) {
            return [];
        }

        $elements = is_array($configured) ? $configured : explode(self::KEY_SEPARATOR, $configured);

        $keys = [];

        foreach ($elements as $element) {
            if (! is_string($element)) {
                continue;
            }

            foreach (explode(self::KEY_SEPARATOR, $element) as $key) {
                $key = trim($key);

                if ($key !== '') {
                    $keys[] = $key;
                }
            }
        }

        return $keys;
    }

    /**
     * Whether the given headers carry a valid, fresh signature for this path.
     *
     * Every failure mode returns false instead of throwing. The length checks
     * matter: base64_decode('', true) returns an empty string rather than false,
     * and sodium_crypto_sign_verify_detached() throws on a signature that is not
     * exactly SODIUM_CRYPTO_SIGN_BYTES long, which would turn an unsigned request
     * into a 500 and give away that the endpoint exists.
     *
     * A configured key that does not decode to an ed25519 public key is skipped,
     * so one malformed entry does not lock out the callers holding the others.
     * The signature is checked against every usable key, without stopping at the
     * first match, so the time a request takes does not depend on which key
     * signed it.
     */
    public function verify(?string $timestamp, ?string $signature, string $path): bool
    {
        $publicKeys = $this->usablePublicKeys();

        if ($publicKeys === []) {
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

        $message = self::message($timestamp, $path);
        $verified = false;

        foreach ($publicKeys as $publicKey) {
            // Evaluate the verification before the OR, so every key is checked.
            $verified = sodium_crypto_sign_verify_detached($decodedSignature, $message, $publicKey) || $verified;
        }

        return $verified;
    }

    /**
     * The configured keys that decode to a raw ed25519 public key.
     *
     * @return list<string>
     */
    private function usablePublicKeys(): array
    {
        $usable = [];

        foreach (self::keys($this->publicKey) as $encoded) {
            $decoded = base64_decode($encoded, true);

            if ($decoded !== false && strlen($decoded) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                $usable[] = $decoded;
            }
        }

        return $usable;
    }
}
