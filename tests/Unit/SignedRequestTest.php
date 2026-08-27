<?php

declare(strict_types=1);

namespace Woweb\VersionEndpoint\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Woweb\VersionEndpoint\Support\SignedRequest;

class SignedRequestTest extends TestCase
{
    #[Test]
    public function the_message_is_the_timestamp_and_the_path_on_two_lines(): void
    {
        $this->assertSame("1750000000\n/__version", SignedRequest::message('1750000000', '/__version'));
    }

    #[Test]
    public function it_accepts_a_fresh_signature_over_the_requested_path(): void
    {
        [$public, $secret] = $this->keypair();
        $timestamp = (string) time();

        $verifier = new SignedRequest($public, 60);

        $this->assertTrue($verifier->verify($timestamp, $this->sign($secret, $timestamp, '/__version'), '/__version'));
    }

    #[Test]
    public function it_rejects_a_signature_from_another_keypair(): void
    {
        [$public] = $this->keypair();
        [, $otherSecret] = $this->keypair();
        $timestamp = (string) time();

        $verifier = new SignedRequest($public, 60);

        $this->assertFalse($verifier->verify($timestamp, $this->sign($otherSecret, $timestamp, '/__version'), '/__version'));
    }

    #[Test]
    public function it_rejects_a_timestamp_outside_the_skew_window(): void
    {
        [$public, $secret] = $this->keypair();
        $verifier = new SignedRequest($public, 60);

        foreach ([time() - 61, time() + 61] as $drifted) {
            $timestamp = (string) $drifted;

            $this->assertFalse($verifier->verify($timestamp, $this->sign($secret, $timestamp, '/__version'), '/__version'));
        }
    }

    #[Test]
    public function it_accepts_a_timestamp_at_the_edge_of_the_skew_window(): void
    {
        [$public, $secret] = $this->keypair();
        $verifier = new SignedRequest($public, 60);
        $timestamp = (string) (time() - 59);

        $this->assertTrue($verifier->verify($timestamp, $this->sign($secret, $timestamp, '/__version'), '/__version'));
    }

    #[Test]
    public function it_returns_false_rather_than_throwing_on_unusable_input(): void
    {
        [$public, $secret] = $this->keypair();
        $timestamp = (string) time();
        $valid = $this->sign($secret, $timestamp, '/__version');

        $cases = [
            'no public key' => ['', $timestamp, $valid],
            'public key of the wrong length' => [base64_encode('short'), $timestamp, $valid],
            'public key that is not base64' => ['!!!', $timestamp, $valid],
            'no timestamp' => [$public, null, $valid],
            'blank timestamp' => [$public, '  ', $valid],
            'no signature' => [$public, $timestamp, null],
            'empty signature' => [$public, $timestamp, ''],
            'signature of the wrong length' => [$public, $timestamp, base64_encode('too-short')],
            'signature that is not base64' => [$public, $timestamp, '!!!not-base64!!!'],
        ];

        foreach ($cases as $name => [$key, $stamp, $signature]) {
            $this->assertFalse(
                (new SignedRequest($key, 60))->verify($stamp, $signature, '/__version'),
                "expected a rejection for: {$name}",
            );
        }
    }

    /** @return array{0: string, 1: string} base64 public key, raw secret key */
    private function keypair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            base64_encode(sodium_crypto_sign_publickey($pair)),
            sodium_crypto_sign_secretkey($pair),
        ];
    }

    private function sign(string $secret, string $timestamp, string $path): string
    {
        return base64_encode(sodium_crypto_sign_detached(SignedRequest::message($timestamp, $path), $secret));
    }
}
