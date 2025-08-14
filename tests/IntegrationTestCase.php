<?php
declare(strict_types=1);

namespace Tests;

use MonkeysLegion\DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use MonkeysLegion\Http\MiddlewareDispatcher;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\UriFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use MonkeysLegion\Http\CoreRequestHandler;

/**
 * Base class for HTTP integration tests.
 *
 * Sets up the DI container and HTTP middleware pipeline.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected ContainerInterface $container;
    protected MiddlewareDispatcher $dispatcher;

    protected function setUp(): void
    {
        // Build PHP-DI container using app config
        $builder = new ContainerBuilder();
        $builder->addDefinitions(require __DIR__ . '/../config/app.php');
        $this->container = $builder->build();

        // Get the HTTP pipeline
        $this->dispatcher = $this->container->get(MiddlewareDispatcher::class);
    }

    /**
     * Create a ServerRequest for testing.
     */
    protected function createRequest(
        string $method,
        string $uri,
        array $headers = [],
        string|null $body = null
    ) {
        $requestFactory = new ServerRequestFactory();
        $uriFactory     = new UriFactory();

        $request = $requestFactory->createServerRequest($method, $uriFactory->createUri($uri));
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $streamFactory = $this->container->get(StreamFactoryInterface::class);
            $stream        = $streamFactory->createStream($body);
            $request       = $request->withBody($stream);
        }

        return $request;
    }

    /**
     * Dispatch the request through your PSR-15 pipeline and return the response.
     */
    protected function dispatch($request)
    {
        // CoreRequestHandler is the final handler in the pipeline
        $coreHandler = $this->container->get(CoreRequestHandler::class);
        return $this->dispatcher->process($request, $coreHandler);
    }

    /**
     * Assert the response has the expected HTTP status.
     */
    protected function assertStatus(
        ResponseInterface $response,
        int $expected
    ): void {
        $this->assertSame(
            $expected,
            $response->getStatusCode(),
            'Unexpected status code'
        );
    }

    /**
     * Assert the response JSON matches the given array.
     */
    protected function assertJsonResponse(
        ResponseInterface $response,
        array $expected
    ): void {
        // Check Content-Type header contains “application/json”
        $this->assertStringContainsString(
            'application/json',
            $response->getHeaderLine('Content-Type'),
            'Response is not JSON'
        );

        $body = (string) $response->getBody();
        $this->assertJson($body, 'Response body is not valid JSON');

        $actual = json_decode($body, true);
        $this->assertEquals($expected, $actual);
    }
}
