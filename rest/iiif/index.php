<?php
require __DIR__ . '/../vendor/autoload.php';

use Slim\Http\Request;
use Slim\Http\Response;
use function OpenApi\scan;
//        "zircote/swagger-php": "^3.1"


/**
 * @OA\Info(title="JACQ Webservices: iiif", version="0.1")
 */

/************************
 * include all settings *
 ************************/
include __DIR__ . '/../inc/variables.php';
$settings = [
    'settings' => [
        'displayErrorDetails' => $_CONFIG['displayErrorDetails'], // set to false in production
        'addContentLengthHeader' => false, // Allow the web server to send the content-length header

        'db' => [
            'host'     => $_CONFIG['DATABASES']['HERBARINPUT']['host'],
            'database' => $_CONFIG['DATABASES']['HERBARINPUT']['db'],
            'username' => $_CONFIG['DATABASES']['HERBARINPUT']['user'],
            'password' => $_CONFIG['DATABASES']['HERBARINPUT']['pass']
        ],

        // Monolog settings
        'logger' => [
            'name' => 'slim-app',
            'path' => __DIR__ . '/../logs/iiif.log',
            'level' => \Monolog\Logger::DEBUG,
        ],
    ],
];



/***********************
 * Instantiate the app *
 ***********************/
$app = new \Slim\App($settings);



/***********************
 * Set up dependencies *
 ***********************/
$container = $app->getContainer();
// monolog
$container['logger'] = function ($c)
{
    $settings = $c->get('settings')['logger'];
    $logger = new \Monolog\Logger($settings['name']);
    $logger->pushProcessor(new \Monolog\Processor\UidProcessor());
    $logger->pushHandler(new \Monolog\Handler\StreamHandler($settings['path'], $settings['level']));
    return $logger;
};

$container['db'] = function ($c)
{
    $db = $c['settings']['db'];
    $dbLink = new mysqli($db['host'], $db['username'], $db['password'], $db['database']);
    $dbLink->set_charset('utf8');
    return $dbLink;
};

//Add container to handle all runtime exceptions/errors, fail safe and return json
//works only for PHP 7.x
$container['phpErrorHandler'] = function ($container) {
    return function ($request, $response, $exception) use ($container) {
        $data = [
            'message' => $exception->getMessage()
        ];
        $jsonResponse = $response->withStatus(500)->withJson($data);
        return $jsonResponse;
    };
};



/***********************
 * Register middleware *
 ***********************/
$app->add(function (Request $request, Response $response, $next)
{
    $newResponse = $next($request, $response);
    return $newResponse
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', 'GET');
});



/*******************
 * Register routes *
 *******************/
/**
 * @OA\Get(
 *  path="/manifestUri/{specimenID}",
 *  summary="get the manifest URI for a given specimen-ID",
 *  @OA\Parameter(
 *      name="specimenID",
 *      in="path",
 *      description="ID of specimen",
 *      required=true,
 *      @OA\Schema(type="integer")
 *  ),
*  @OA\Response(response="200", description="successful operation"),
 * )
 */
$app->get('/manifestUri/{specimenID}', function (Request $request, Response $response, array $args)
{
//    $this->logger->addInfo("called manifest ");

    $mapper = new IiifMapper($this->db);
    $specimenID = intval(filter_var($args['specimenID'], FILTER_SANITIZE_NUMBER_INT));

    $data = $mapper->getManifestUri($specimenID);

    $jsonResponse = $response->withJson($data);
    return $jsonResponse;
});

/**
 * @OA\Get(
 *  path="/manifest/{specimenID}",
 *  summary="get the manifest for a given specimen-ID",
 *  @OA\Parameter(
 *      name="specimenID",
 *      in="path",
 *      description="ID of specimen",
 *      required=true,
 *      @OA\Schema(type="integer")
 *  ),
*  @OA\Response(response="200", description="successful operation"),
 * )
 */
$app->get('/manifest/{specimenID}', function (Request $request, Response $response, array $args)
{
//    $this->logger->addInfo("called manifest ");

    $mapper = new IiifMapper($this->db);
    $specimenID = intval(filter_var($args['specimenID'], FILTER_SANITIZE_NUMBER_INT));

    $manifest = $mapper->getManifest($specimenID);
    if ($manifest === false) {
        $manifestUri = $mapper->getManifestUri($specimenID);
        return $response->withRedirect($manifestUri['uri'], 303);
    } else {
        $jsonResponse = $response->withJson($manifest);
        return $jsonResponse;
    }
});

/**
 * @OA\Get(
 *     path="/openapi",
 *     tags={"documentation"},
 *     summary="OpenAPI JSON File that describes the API",
 *     @OA\Response(response="200", description="OpenAPI Description File"),
 * )
 */
$app->get('/openapi', function ($request, $response, $args) {
    $swagger = scan(__DIR__);
    $jsonResponse = $response->withJson($swagger);
    return $jsonResponse;
});

$app->get('/description', function($request, $response, $args) {
    return file_get_contents('description.html');
});

// Catch-all route to serve a 404 Not Found page if none of the routes match
// this route has to be defined as last route
$app->get('/{routes:.+}', function (Request $request, Response $response)
{
    // catch-all log message
    $this->logger->addInfo("catch-all route for /" . $request->getUri()->getPath());

    $handler = $this->notFoundHandler; // handle using the default Slim page not found handler
    return $handler($request, $response);
});




/***********
 * Run app *
 ***********/
$app->run();
