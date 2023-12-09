<?php
require __DIR__ . '/../vendor/autoload.php';

use Slim\Http\Request;
use Slim\Http\Response;
use function OpenApi\scan;
//        "zircote/swagger-php": "^3.1"


/**
 * @OA\Info(title="JACQ Webservices: images", version="0.1")
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
            'path' => __DIR__ . '/../logs/images.log',
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
 *  path="/show/{specimenID}",
 *  summary="get the uri to show the first image of a given specimen-ID with a redirect (303)",
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
$app->get('/show/{specimenID}', function (Request $request, Response $response, array $args)
{
//    $this->logger->addInfo("called show ");

    $specimenID = intval(filter_var($args['specimenID'], FILTER_SANITIZE_NUMBER_INT));
    $mapper = new ImageLinkMapper($this->db, $specimenID);

    $imageLink = $mapper->getFirstImageShowLink();
    if ($imageLink) {
        return $response->withRedirect($imageLink, 303);
    } else {
        $handler = $this->notFoundHandler; // handle using the default Slim page not found handler
        return $handler($request, $response);
    }
});

/**
 * @OA\Get(
 *  path="/download/{specimenID}",
 *  summary="get the uri to download the first image of a given specimen-ID with a redirect (303)",
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
$app->get('/download/{specimenID}', function (Request $request, Response $response, array $args)
{
//    $this->logger->addInfo("called download ");

    $mapper = new ImageLinkMapper($this->db, intval(filter_var($args['specimenID'], FILTER_SANITIZE_NUMBER_INT)));

    $imageLink = $mapper->getFirstImageDownloadLink();
    if ($imageLink) {
        return $response->withRedirect($imageLink, 303);
    } else {
        $handler = $this->notFoundHandler; // handle using the default Slim page not found handler
        return $handler($request, $response);
    }
});

/**
 * @OA\Get(
 *  path="/europeana/{specimenID}",
 *  summary="get the uri to download the first image of a given specimen-ID with resolution 1200,x with a redirect (303)",
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
$app->get('/europeana/{specimenID}', function (Request $request, Response $response, array $args)
{
//    $this->logger->addInfo("called europeana ");

    $mapper = new ImageLinkMapper($this->db, intval(filter_var($args['specimenID'], FILTER_SANITIZE_NUMBER_INT)));

    $imageLink = $mapper->getFirstImageEuropeanaLink();
    if ($imageLink) {
        return $response->withRedirect($imageLink, 303);
    } else {
        $handler = $this->notFoundHandler; // handle using the default Slim page not found handler
        return $handler($request, $response);
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
$app->get('/openapi', function (Request $request, Response $response) {
    $swagger = scan(__DIR__);
    $jsonResponse = $response->withJson($swagger);
    return $jsonResponse;
});

$app->get('/description', function(Request $request, Response $response) {
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
