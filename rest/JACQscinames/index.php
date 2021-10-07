<?php
require __DIR__ . '/../vendor/autoload.php';

use Slim\Http\Request;
use Slim\Http\Response;
use function OpenApi\scan;
//        "zircote/swagger-php": "^3.1"


/**
 * @OA\Info(title="JACQ Webservices: JACQscinames", version="0.1")
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
            'path' => __DIR__ . '/../logs/JACQscinames.log',
            'level' => \Monolog\Logger::DEBUG,
        ],

        'jacq_input_services' => $_CONFIG['JACQ_INPUT_SERVICES'],
        'APIKEY' => $_CONFIG['APIKEY'],
        'classifications_license' => $_CONFIG['classifications_license'],
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
 *  path="/uuid/{taxonID}",
 *  summary="Get uuid, uuid-url and scientific name of a given taxonID",
  *  @OA\Parameter(
 *      name="taxonID",
 *      in="path",
 *      description="ID of taxon name",
 *      required=true,
 *      @OA\Schema(type="integer")
 *  ),
*  @OA\Response(response="200", description="successful operation"),
 * )
 */
$app->get('/uuid/{taxonID}', function (Request $request, Response $response, array $args)
{
//    $this->logger->addInfo("called uuid ");

    $mapper = new JACQscinamesMapper($this->db, array('jacq_input_services' => $this->get('settings')['jacq_input_services'],
                                                      'apikey' => $this->get('settings')['APIKEY']));
    $taxonID = intval(filter_var($args['taxonID'], FILTER_SANITIZE_NUMBER_INT));
    $data = $mapper->getUuid($taxonID);
    $data['taxonID'] = $taxonID;
    $data['scientificName'] = $mapper->getScientificName($taxonID);
    $jsonResponse = $response->withJson($data);
    return $jsonResponse;
});

/**
 * @OA\Get(
 *  path="/name/{taxonID}",
 *  summary="Get scientific name, uuid and uuid-url of a given taxonID",
 *  @OA\Parameter(
 *      name="taxonID",
 *      in="path",
 *      description="ID of taxon name",
 *      required=true,
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Response(response="200", description="successful operation"),
 * )
 */
$app->get('/name/{taxonID}', function (Request $request, Response $response, array $args)
{
//    $this->logger->addInfo("called uuid ");

    $mapper = new JACQscinamesMapper($this->db, array('jacq_input_services' => $this->get('settings')['jacq_input_services'],
                                                      'apikey' => $this->get('settings')['APIKEY']));
    $taxonID = intval(filter_var($args['taxonID'], FILTER_SANITIZE_NUMBER_INT));
    $data = $mapper->getUuid($taxonID);
    $data['taxonID'] = $taxonID;
    $data['scientificName'] = $mapper->getScientificName($taxonID);
    $jsonResponse = $response->withJson($data);
    return $jsonResponse;
});

/**
 * @OA\Get(
 *  path="/search/{term}",
 *  summary="search for scientific names; get taxonIDs and scientific names of search result",
 *  @OA\Parameter(
 *      name="term",
 *      in="path",
 *      description="search term",
 *      required=true,
 *      example="prunus aviu*",
 *      @OA\Schema(type="string")
 *  ),
 *  @OA\Response(response="200", description="successful operation"),
 * )
 */
$app->get('/search/{term}', function (Request $request, Response $response, array $args)
{
//    $this->logger->addInfo("called search ");

    $mapper = new JACQscinamesMapper($this->db, array('jacq_input_services' => $this->get('settings')['jacq_input_services'],
                                                      'apikey' => $this->get('settings')['APIKEY']));
    $data = $mapper->searchScientificName(trim(filter_var($args['term'], FILTER_SANITIZE_STRING)));
    $jsonResponse = $response->withJson($data);
    return $jsonResponse;
});

/**
 * @OA\Get(
 *  path="/resolve/{uuid}",
 *  summary="Get scientific name, uuid-url and taxon-ID of a given uuid",
 *  @OA\Parameter(
 *      name="uuid",
 *      in="path",
 *      description="uuid of taxon name",
 *      required=true,
 *      @OA\Schema(type="string")
 *  ),
 *  @OA\Response(response="200", description="successful operation"),
 * )
 */
$app->get('/resolve/{uuid}', function (Request $request, Response $response, array $args)
{
//    $this->logger->addInfo("called resolve ");

    $mapper = new JACQscinamesMapper($this->db, array('jacq_input_services' => $this->get('settings')['jacq_input_services'],
                                                      'apikey' => $this->get('settings')['APIKEY']));
    $uuid = filter_var($args['uuid'], FILTER_SANITIZE_STRING);
    $data = array('uuid'           => $uuid,
                  'url'            => '',
                  'taxonID'        => intval($mapper->getTaxonID($uuid)),
                  'scientificName' => '');
    if ($data['taxonID']) {
        $buffer = $mapper->getUuid($data['taxonID']);
        $data['url'] = $buffer['url'];
        $data['scientificName'] = $mapper->getScientificName($data['taxonID']);
    }
    $jsonResponse = $response->withJson($data);
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
    // catch-all log message
    $this->logger->addInfo("catch-all '/' route");

    $name = array('catch-all: ' => $args['name']);
    $jsonResponse = $response->withJson($name);
    return $jsonResponse;
});



/***********
 * Run app *
 ***********/
$app->run();
