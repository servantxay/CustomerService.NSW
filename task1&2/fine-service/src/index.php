<?php

/**
 * Fine Service API
 *
 * Entry point for the Fine Service REST API using Slim 4.
 * Provides endpoints for creating, retrieving, updating, deleting, and marking fines as paid.
 *
 * @package FineService
 */

/** Testing Endpoints
 * 
 * Method	Endpoint	    Description
 * POST	    /fines	        Create a fine
 * GET	    /fines	        List all fines
 * GET	    /fines/{id}	    Get a fine
 * PUT	    /fines/{id}	    Update a fine
 * DELETE	/fines/{id}	    Delete a fine
 * PATCH	/fines/{id}     Partially update a fine
 * PATCH	/fines/{id}/pay Mark fine as paid
 */

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Slim\Middleware\BodyParsingMiddleware;
use Slim\Exception\HttpNotFoundException;
use Slim\Exception\HttpMethodNotAllowedException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Database;
use App\FineController;

// Initialize database and tables
Database::init();

/** @var \Slim\App $app Slim application instance */
$app = AppFactory::create();

// Add middleware to parse JSON, form data, and XML request bodies
$app->addBodyParsingMiddleware();
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

$errorMiddleware->setErrorHandler(HttpNotFoundException::class, function (
    Request $request,
    Throwable $exception,
    bool $displayErrorDetails
) use ($app) : Response {
    $response = $app->getResponseFactory()->createResponse();
    $response->getBody()->write(json_encode([
        'error' => 'Route not found',
        'message' => $exception->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(404);
});

// Add middleware to handle invalid methods and output 404 error
$errorMiddleware->setErrorHandler(HttpMethodNotAllowedException::class, function (
    Request $request,
    Throwable $exception,
    bool $displayErrorDetails
) use ($app) : Response {
    $response = $app->getResponseFactory()->createResponse();
    $response->getBody()->write(json_encode([
        'error' => 'Method not allowed',
        'message' => $exception->getMessage(),
        'allowed_methods' => $exception->getAllowedMethods()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(405);
});

// Home route for health check
$app->get('/', function ($request, $response) {
    $response->getBody()->write("Fine Service API is running. Use /fines endpoint.");
    return $response;
});

// All /fines route endpoints
$app->post('/fines[/]', [FineController::class, 'create']);
$app->get('/fines[/]', [FineController::class, 'list']);
$app->get('/fines/{id}[/]', [FineController::class, 'get']);
$app->put('/fines/{id}[/]', [FineController::class, 'update']);
$app->delete('/fines/{id}[/]', [FineController::class, 'delete']);
$app->patch('/fines/{id}[/]', [FineController::class, 'partialUpdate']);
$app->patch('/fines/{id}/pay[/]', [FineController::class, 'markPaid']);

$app->run();