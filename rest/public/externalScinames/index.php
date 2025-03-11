<?php
require __DIR__ . '/../../vendor/autoload.php';

use Jacq\ExternalScinames;
use Slim\Http\Request;
use Slim\Http\Response;


/************************
 * include all settings *
 ************************/
include __DIR__ . '/../../inc/variables.php';
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

        'externalServices' => $_CONFIG['externalScinameServices'],

        // Monolog settings
        'logger' => [
            'name' => 'slim-app',
            'path' => __DIR__ . '/../../logs/externalScinames.log',
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
 *  path="/externalScinames/find/{term}",
 *  tags={"externalScinames"},
 *  summary="search for scientific names; get IDs and scientific names of search result",
 *  @OA\Parameter(
 *      name="term",
 *      in="path",
 *      description="search term",
 *      required=true,
 *      example="Rhopalocarpus alternifolius (Baker) Capuron",
 *      @OA\Schema(type="string")
 *  ),
 *  @OA\Response(response="200", description="successful operation"),
 * )
 */
$app->get('/find/{term}', function (Request $request, Response $response, array $args)
{
//    $this->logger->addInfo("called find ");

    $scanner = new ExternalScinames($this->db, $this->get('settings')['externalServices']);
    $data = $scanner->searchAll($args['term']);
    $jsonResponse = $response->withJson($data);
    return $jsonResponse;
});

$app->get('/', function(Request $request, Response $response)
{
    return file_get_contents('description.html');
});

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
