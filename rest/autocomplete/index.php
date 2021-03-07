<?php
require __DIR__ . '/../vendor/autoload.php';

use Slim\Http\Request;
use Slim\Http\Response;
use function OpenApi\scan;
//        "zircote/swagger-php": "^3.1"


/**
 * @OA\Info(title="JACQ Webservices: autocomplete", version="0.1")
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
            'path' => __DIR__ . '/../logs/autocomplete.log',
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



/*******************
 * Register routes *
 *******************/
/**
 * @OA\Get(
 *  path="/scientificNames/{term}",
 *  summary="Search for fitting scientific names and return them",
 *  @OA\Parameter(
 *      name="term",
 *      in="path",
 *      description="part of a scientific name to autocomplete",
 *      required=true,
 *      @OA\Schema(type="string")
 *  ),
 *  @OA\Response(response="200", description="successful operation"),
 * )
 */
$app->get('/scientificNames/{term}', function (Request $request, Response $response, array $args)
{
//    $this->logger->addInfo("called scientificNames with <" . $args['term'] . ">");

    $mapper = new AutocompleteMapper($this->db);
    $names = $mapper->getScientificNames(trim(filter_var($args['term'], FILTER_SANITIZE_STRING)));
    $jsonResponse = $response->withJson($names);
    return $jsonResponse;
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

$app->get('/[{name}]', function (Request $request, Response $response, array $args)
{
    // Sample log message
    $this->logger->addInfo("Slim-Skeleton '/' route");

    $name = array('catch-all: ' => $args['name']);
    $jsonResponse = $response->withJson($name);
    return $jsonResponse;
});



/***********
 * Run app *
 ***********/
$app->run();
