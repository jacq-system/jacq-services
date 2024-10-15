<?php
require __DIR__ . '/../../vendor/autoload.php';

use Slim\Http\Request;
use Slim\Http\Response;
use function OpenApi\scan;
//        "zircote/swagger-php": "^3.1"


/**
 * @OA\Info(title="JACQ Webservices: statistics", version="0.1")
 */

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

        // Monolog settings
        'logger' => [
            'name' => 'slim-app',
            'path' => __DIR__ . '/../../logs/statistics.log',
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
 *  path="/results/{periodStart}/{periodEnd}/{updated}/{type}/{interval}",
 *  summary="Get statistics result for given type, interval and period",
 *  @OA\Parameter(
 *      name="periodStart",
 *      in="path",
 *      description="start of period (yyyy-mm-dd)",
 *      required=true,
 *      @OA\Schema(type="string")
 *  ),
 *  @OA\Parameter(
 *      name="periodEnd",
 *      in="path",
 *      description="end of period (yyyy-mm-dd)",
 *      required=true,
 *      @OA\Schema(type="string")
 *  ),
 *  @OA\Parameter(
 *      name="updated",
 *      in="path",
 *      description="new (0) or updated (1) types only",
 *      required=true,
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Parameter(
 *      name="type",
 *      in="path",
 *      description="type of statistics analysis",
 *      required=true,
 *      @OA\Schema(
 *          type="string",
 *          enum={"names", "citations", "names_citations", "specimens", "type_specimens", "names_type_specimens", "types_name", "synonyms"}
 *      )
 *  ),
 *  @OA\Parameter(
 *      name="interval",
 *      in="path",
 *      description="resolution of statistics analysis",
 *      required=true,
 *      @OA\Schema(
 *          type="string",
 *          enum={"day", "week", "month", "year"}
 *      )
 *  ),
 *  @OA\Response(response="200", description="successful operation"),
 * )
 */
$app->get('/results/{periodStart}/{periodEnd}/{updated}/{type}/{interval}', function (Request $request, Response $response, array $args)
{
//    $this->logger->addInfo("called results with <" . $args['interval'] . ">");

    $mapper = new StatisticsMapper($this->db);
    $names = $mapper->getResults(substr(trim(filter_var($args['periodStart'], FILTER_SANITIZE_STRING)), 0, 10),
                                 substr(trim(filter_var($args['periodEnd'], FILTER_SANITIZE_STRING)), 0, 10),
                                 ((!empty($args['updated'])) ? 1 : 0),
                                 trim(filter_var($args['type'], FILTER_SANITIZE_STRING)),
                                 trim(filter_var($args['interval'], FILTER_SANITIZE_STRING)));
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
