<?php
require __DIR__ . '/../../vendor/autoload.php';

use Slim\Http\Request;
use Slim\Http\Response;
use function OpenApi\scan;
//        "zircote/swagger-php": "^3.1"


/**
 * @OA\Info(title="JACQ Webservices: livingplants", version="0.1")
 */

/************************
 * include all settings *
 ************************/
include __DIR__ . '/../../inc/variables.php';
$settings = [
    'settings' => [
        'displayErrorDetails' => $_CONFIG['displayErrorDetails'], // set to false in production
        'addContentLengthHeader' => false, // Allow the web server to send the content-length header

        'dbji' => [
            'host'     => $_CONFIG['DATABASES']['JACQINPUT']['host'],
            'database' => $_CONFIG['DATABASES']['JACQINPUT']['db'],
            'username' => $_CONFIG['DATABASES']['JACQINPUT']['user'],
            'password' => $_CONFIG['DATABASES']['JACQINPUT']['pass']
        ],

        // Monolog settings
        'logger' => [
            'name' => 'slim-app',
            'path' => __DIR__ . '/../../logs/lp.log',
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

$container['dbji'] = function ($c)
{
    $db = $c['settings']['dbji'];
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
 *  path="/derivatives",
 *  summary="find all derivatives which fit given criteria",
 *  @OA\Parameter(
 *      name="org",
 *      in="query",
 *      description="optional id of organisation (and its children), defaults to all",
 *      example="4",
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Parameter(
 *      name="separated",
 *      in="query",
 *      description="optional status of separated bit (0 or 1), defaults to all",
 *      example="0",
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Parameter(
 *      name="derivativeID",
 *      in="query",
 *      description="optional derivate-id; if given, only the derivative with this id will be returned, defaults to all",
 *      example="1645",
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Response(response="200", description="successful operation"),
 * )
 */
$app->get('/derivatives', function (Request $request, Response $response)
{
//    $this->logger->addInfo("called derivatives ");

    $criteria = array();
    if (isset($request->getQueryParams()['org'])) {
        $organisation = new Jacq\Input\Organisation($this->dbji, intval($request->getQueryParams()['org']));
        $criteria['organisationIds'] = $organisation->getAllChildren();
    }
    if (isset($request->getQueryParams()['separated'])) {
        $criteria['separated'] = intval($request->getQueryParams()['separated']);
    }
    if (isset($request->getQueryParams()['derivativeID'])) {
        $criteria['derivativeID'] = intval($request->getQueryParams()['derivativeID']);
    }

    $classificationManager = new Jacq\Input\ClassificationManager($this->dbji, [10400, 26389]);
    $derivativeManager = new Jacq\Input\DerivativeManager($this->dbji, $classificationManager);

    $jsonResponse = $response->withJson($derivativeManager->getList($criteria));
    return $jsonResponse;
    // https://www.convertcsv.com/json-to-csv.htm
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

//$app->get('/description', function(Request $request, Response $response) {
//    return file_get_contents('description.html');
//});
//
//$app->get('/', function(Request $request, Response $response)
//{
//    return file_get_contents('description.html');
//});

// Catch-all route to serve a 404 Not Found page if none of the routes match
// this route has to be defined as last route
$app->map(['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], '/{routes:.+}', function (Request $request, Response $response)
{
    // catch-all log message
    $this->logger->addInfo("catch-all route for /" . $request->getUri()->getPath() . " with method " . $request->getMethod());

    $handler = $this->notFoundHandler; // handle using the default Slim page not found handler
    return $handler($request, $response);
});




/***********
 * Run app *
 ***********/
$app->run();
