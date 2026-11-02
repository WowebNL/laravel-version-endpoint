<?php

declare(strict_types=1);

namespace Woweb\VersionEndpoint\Tests;

use PHPUnit\Framework\Attributes\Test;

class MultipleVerifyKeysTest extends TestCase
{
    #[Test]
    public function every_caller_in_a_comma_separated_list_can_read_the_endpoint(): void
    {
        [$firstPublic, $firstSecret] = $this->keypair();
        [$secondPublic, $secondSecret] = $this->keypair();

        config(['version-endpoint.verify_key' => $firstPublic.','.$secondPublic]);

        $this->getJson('/__version', $this->signedHeaders($firstSecret))->assertOk();
        $this->getJson('/__version', $this->signedHeaders($secondSecret))->assertOk();
    }

    #[Test]
    public function a_caller_whose_key_is_not_listed_gets_a_404(): void
    {
        [$firstPublic] = $this->keypair();
        [$secondPublic] = $this->keypair();
        [, $otherSecret] = $this->keypair();

        config(['version-endpoint.verify_key' => $firstPublic.','.$secondPublic]);

        $this->getJson('/__version', $this->signedHeaders($otherSecret))->assertNotFound();
    }

    #[Test]
    public function removing_a_key_from_the_list_revokes_only_that_caller(): void
    {
        [$keptPublic, $keptSecret] = $this->keypair();
        [$revokedPublic, $revokedSecret] = $this->keypair();

        config(['version-endpoint.verify_key' => $keptPublic.','.$revokedPublic]);

        $this->getJson('/__version', $this->signedHeaders($keptSecret))->assertOk();
        $this->getJson('/__version', $this->signedHeaders($revokedSecret))->assertOk();

        config(['version-endpoint.verify_key' => $keptPublic]);

        $this->getJson('/__version', $this->signedHeaders($keptSecret))->assertOk();
        $this->getJson('/__version', $this->signedHeaders($revokedSecret))->assertNotFound();
    }

    #[Test]
    public function a_published_config_can_list_the_keys_as_an_array(): void
    {
        [$firstPublic, $firstSecret] = $this->keypair();
        [$secondPublic, $secondSecret] = $this->keypair();

        config(['version-endpoint.verify_key' => [$firstPublic, $secondPublic]]);

        $this->getJson('/__version', $this->signedHeaders($firstSecret))->assertOk();
        $this->getJson('/__version', $this->signedHeaders($secondSecret))->assertOk();
    }

    #[Test]
    public function the_environment_variable_carries_the_list_into_config(): void
    {
        [$firstPublic, $firstSecret] = $this->keypair();
        [$secondPublic, $secondSecret] = $this->keypair();

        $_SERVER['REGISTER_VERIFY_KEY'] = $firstPublic.', '.$secondPublic;

        try {
            $config = require __DIR__.'/../config/version-endpoint.php';
        } finally {
            unset($_SERVER['REGISTER_VERIFY_KEY']);
        }

        config(['version-endpoint.verify_key' => $config['verify_key']]);

        $this->getJson('/__version', $this->signedHeaders($firstSecret))->assertOk();
        $this->getJson('/__version', $this->signedHeaders($secondSecret))->assertOk();
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
}
