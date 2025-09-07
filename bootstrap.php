<?php
declare(strict_types=1);

use MonkeysLegion\Core\Routing\RouteLoader;
use MonkeysLegion\DI\ContainerBuilder;
use MonkeysLegion\Http\Emitter\SapiEmitter;
use MonkeysLegion\Http\Message\ServerRequest;
use MonkeysLegion\Router\Router;
use Psr\Http\Message\ServerRequestInterface;

define('ML_BASE_PATH', __DIR__);

// 0) Autoload dependencies
require __DIR__ . '/vendor/autoload.php';

// Optional: allow forcing web/cli behavior via env
$skipRouteLoad = (PHP_SAPI === 'cli') || !empty($_ENV['SKIP_ROUTE_LOAD']);

// 1) Build DI container
$container = new ContainerBuilder()
    ->addDefinitions(require __DIR__ . '/config/app.php')
    ->build();

// === CLI / WORKER MODE ======================================================
if ($skipRouteLoad) {
    // Do NOT load controllers or start the HTTP stack in CLI.
    // Return a tiny wrapper so bin scripts can fetch the container.
    return new class($container) {
        public function __construct(private \Psr\Container\ContainerInterface $c) {}
        public function getContainer(): \Psr\Container\ContainerInterface { return $this->c; }
    };
}

// === WEB MODE ===============================================================

// 2) Auto-discover controller routes
$container
    ->get(RouteLoader::class)
    ->loadControllers();

// 3) Create PSR-7 request and resolve router
$request = ServerRequest::fromGlobals();
$router  = $container->get(Router::class);

// 4) Handle CORS and dispatch through the router
$cors     = $container->get(\MonkeysLegion\Core\Middleware\CorsMiddleware::class);
$response = $cors(
    $request,
    fn(ServerRequestInterface $req) => $router->dispatch($req)
);

// 5) Emit the HTTP response
new SapiEmitter()->emit($response);
