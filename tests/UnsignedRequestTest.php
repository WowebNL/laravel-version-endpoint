<?php

declare(strict_types=1);

namespace Woweb\VersionEndpoint\Tests;

use Illuminate\Routing\Router;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Without a valid signature the endpoint must answer exactly like a path the
 * application does not have: same status, same body, same headers, for every
 * method the router knows, and no matter how often it is asked.
 */
class UnsignedRequestTest extends TestCase
{
    private const MISSING = '/__does-not-exist';

    protected function defineEnvironment($app): void
    {
        $app['env'] = 'production';
        $app['config']->set('app.debug', false);
        $app['config']->set('version-endpoint.throttle', '3,1');
    }

    /** @return iterable<string, array{string, array<string, string>}> */
    public static function methods(): iterable
    {
        foreach (Router::$verbs as $method) {
            yield $method.' as html' => [$method, ['Accept' => 'text/html']];
            yield $method.' as json' => [$method, ['Accept' => 'application/json']];
        }
    }

    #[Test]
    #[DataProvider('methods')]
    public function it_answers_an_unsigned_request_like_a_path_that_does_not_exist(string $method, array $headers): void
    {
        $this->signingKeypair();

        $endpoint = $this->call($method, '/__version', server: $this->serverHeaders($headers));
        $missing = $this->call($method, self::MISSING, server: $this->serverHeaders($headers));

        $this->assertSame(404, $missing->getStatusCode());

        $expected = $this->fingerprint($missing, self::MISSING);
        $actual = $this->fingerprint($endpoint, '/__version');

        // A response to HEAD never carries a body on the wire. In-process, the
        // router strips it from a matched route's response but not from one for
        // an unmatched path, so only the status and headers are comparable.
        if ($method === 'HEAD') {
            [$expected[1], $actual[1]] = ['', ''];
        }

        $this->assertSame($expected, $actual);
    }

    #[Test]
    public function it_does_not_count_unsigned_requests_against_the_rate_limit(): void
    {
        $this->signingKeypair();
        $missing = $this->fingerprint($this->getJson(self::MISSING), self::MISSING);

        // Well past the allowance of three: every answer stays an ordinary 404.
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame($missing, $this->fingerprint($this->getJson('/__version'), '/__version'));
        }

        $this->getJson('/__version', $this->signedHeaders())->assertOk();
    }

    #[Test]
    public function it_keeps_answering_a_signed_caller_while_another_address_floods_the_path(): void
    {
        $this->signingKeypair();

        for ($i = 0; $i < 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])->getJson('/__version')->assertNotFound();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->getJson('/__version', $this->signedHeaders())
            ->assertOk();
    }

    #[Test]
    public function it_still_rate_limits_signed_requests(): void
    {
        $this->signingKeypair();

        $this->getJson('/__version', $this->signedHeaders())->assertOk()->assertHeader('X-RateLimit-Limit', '3');
        $this->getJson('/__version', $this->signedHeaders())->assertOk();
        $this->getJson('/__version', $this->signedHeaders())->assertOk();
        $this->getJson('/__version', $this->signedHeaders())->assertStatus(429);
    }

    #[Test]
    public function it_only_serves_the_payload_on_get_and_head(): void
    {
        $this->signingKeypair();

        $this->call('HEAD', '/__version', server: $this->serverHeaders($this->signedHeaders()))->assertOk();

        foreach (array_diff(Router::$verbs, ['GET', 'HEAD']) as $method) {
            $this->call($method, '/__version', server: $this->serverHeaders($this->signedHeaders()))
                ->assertNotFound();
        }
    }

    /**
     * What an outside observer can compare: the status, the body with the
     * requested path taken out, and every header except the date.
     *
     * @return array{int, string, array<string, list<string|null>>}
     */
    private function fingerprint(TestResponse $response, string $path): array
    {
        $headers = $response->headers->all();
        unset($headers['date']);
        ksort($headers);

        return [
            $response->getStatusCode(),
            str_replace(ltrim($path, '/'), '{path}', (string) $response->getContent()),
            $headers,
        ];
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function serverHeaders(array $headers): array
    {
        return $this->transformHeadersToServerVars($headers);
    }
}
