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

    #[Test]
    public function it_accepts_a_signature_from_any_key_in_a_comma_separated_list(): void
    {
        [$firstPublic, $firstSecret] = $this->keypair();
        [$secondPublic, $secondSecret] = $this->keypair();
        $timestamp = (string) time();

        $verifier = new SignedRequest($firstPublic.','.$secondPublic, 60);

        $this->assertTrue($verifier->verify($timestamp, $this->sign($firstSecret, $timestamp, '/__version'), '/__version'));
        $this->assertTrue($verifier->verify($timestamp, $this->sign($secondSecret, $timestamp, '/__version'), '/__version'));
    }

    #[Test]
    public function it_rejects_a_signature_from_a_key_that_is_not_in_the_list(): void
    {
        [$firstPublic] = $this->keypair();
        [$secondPublic] = $this->keypair();
        [, $otherSecret] = $this->keypair();
        $timestamp = (string) time();

        $verifier = new SignedRequest($firstPublic.','.$secondPublic, 60);

        $this->assertFalse($verifier->verify($timestamp, $this->sign($otherSecret, $timestamp, '/__version'), '/__version'));
    }

    #[Test]
    public function it_ignores_whitespace_and_empty_entries_in_the_list(): void
    {
        [$firstPublic, $firstSecret] = $this->keypair();
        [$secondPublic, $secondSecret] = $this->keypair();
        $timestamp = (string) time();

        $verifier = new SignedRequest(' , '.$firstPublic.' ,, '.$secondPublic.' , ', 60);

        $this->assertTrue($verifier->verify($timestamp, $this->sign($firstSecret, $timestamp, '/__version'), '/__version'));
        $this->assertTrue($verifier->verify($timestamp, $this->sign($secondSecret, $timestamp, '/__version'), '/__version'));
    }

    #[Test]
    public function it_accepts_the_keys_as_an_array(): void
    {
        [$firstPublic, $firstSecret] = $this->keypair();
        [$secondPublic, $secondSecret] = $this->keypair();
        $timestamp = (string) time();

        $verifier = new SignedRequest([$firstPublic, $secondPublic], 60);

        $this->assertTrue($verifier->verify($timestamp, $this->sign($firstSecret, $timestamp, '/__version'), '/__version'));
        $this->assertTrue($verifier->verify($timestamp, $this->sign($secondSecret, $timestamp, '/__version'), '/__version'));
    }

    #[Test]
    public function a_malformed_key_in_the_list_does_not_lock_out_the_others(): void
    {
        [$public, $secret] = $this->keypair();
        $timestamp = (string) time();

        foreach (['!!!', base64_encode('far too short')] as $malformed) {
            foreach ([$malformed.','.$public, $public.','.$malformed] as $configured) {
                $this->assertTrue(
                    (new SignedRequest($configured, 60))->verify($timestamp, $this->sign($secret, $timestamp, '/__version'), '/__version'),
                    "expected a match for: {$configured}",
                );
            }
        }
    }

    #[Test]
    public function a_list_without_a_usable_key_rejects_every_request(): void
    {
        [, $secret] = $this->keypair();
        $timestamp = (string) time();
        $signature = $this->sign($secret, $timestamp, '/__version');

        foreach ([',', ' , , ', '!!!,'.base64_encode('far too short'), [], [''], null] as $configured) {
            $this->assertFalse((new SignedRequest($configured, 60))->verify($timestamp, $signature, '/__version'));
        }
    }

    #[Test]
    public function the_key_list_is_split_on_commas_and_trimmed(): void
    {
        $this->assertSame([], SignedRequest::keys(null));
        $this->assertSame([], SignedRequest::keys(''));
        $this->assertSame([], SignedRequest::keys(' , ,'));
        $this->assertSame(['a'], SignedRequest::keys('a'));
        $this->assertSame(['a', 'b'], SignedRequest::keys(' a ,, b ,'));
        $this->assertSame(['a', 'b', 'c'], SignedRequest::keys(['a', ' b,c ', '', 42]));
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
