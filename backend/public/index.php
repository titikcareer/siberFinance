<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

// --- Load .env ---
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// --- Build DI Container ---
$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(__DIR__ . '/../src/Config/dependencies.php');
$container = $containerBuilder->build();

AppFactory::setContainer($container);
$app = AppFactory::create();

// Diperlukan agar route seperti /accounts/{id} & body parsing berjalan benar di balik proxy/subfolder
$app->setBasePath('');

// --- Parsing JSON body otomatis ---
$app->addBodyParsingMiddleware();

// --- CORS (untuk komunikasi dengan frontend Lit yang berjalan di origin berbeda) ---
$app->add(function (Request $request, $handler) {
    $response = $handler->handle($request);
    $allowedOrigin = $_ENV['CORS_ALLOWED_ORIGIN'] ?? '*';

    return $response
        ->withHeader('Access-Control-Allow-Origin', $allowedOrigin)
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
});

$app->options('/{routes:.+}', function (Request $request, Response $response) {
    return $response;
});

// --- Error Middleware (tampilkan detail hanya saat APP_DEBUG=true) ---
$debug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
$app->addErrorMiddleware($debug, true, true);

// --- Routing ---
$routes = require __DIR__ . '/../src/Routes/api.php';
$routes($app);

$app->run();
