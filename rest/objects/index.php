<?php
require __DIR__ . '/../vendor/autoload.php';

use Slim\Http\Request;
use Slim\Http\Response;
use function OpenApi\scan;
//        "zircote/swagger-php": "^3.1"


/**
 * @OA\Info(title="JACQ Webservices: objects", version="0.1")
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
            'path' => __DIR__ . '/../logs/objects.log',
            'level' => \Monolog\Logger::DEBUG,
        ],

        'jacq_input_services' => $_CONFIG['JACQ_INPUT_SERVICES'],
        'APIKEY' => $_CONFIG['APIKEY'],
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
$app->post('/specimens/fromList', function (Request $request, Response $response)
{
//    $this->logger->addInfo("called specimens/fromList ");

    $mapper = new ObjectsMapper($this->db);

    $data = $mapper->getSpecimensFromList($request->getParsedBody());

    $jsonResponse = $response->withJson($data);
    return $jsonResponse;

});

/**
 * @OA\Get(
 *  path="/specimens/search",
 *  summary="search for all specimens which fit given criteria",
  *  @OA\Parameter(
 *      name="p",
 *      in="query",
 *      description="optional number of page to display, starts with 0 (first page), defaults to 0",
 *      example="2",
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Parameter(
 *      name="rpp",
 *      in="query",
 *      description="optional number of records per page to display (<= 100), defaults to 50",
 *      example="20",
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Parameter(
 *      name="list",
 *      in="query",
 *      description="optional switch if all specimen data should be returned (=0) or just a list of specimen-IDs (=1), defaults to 1",
 *      example="1",
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Parameter(
 *      name="term",
 *      in="query",
 *      description="optional search term for scientific names, use * as a wildcard",
 *      example="prunus av*",
 *      @OA\Schema(type="string")
 *  ),
 *  @OA\Parameter(
 *      name="sc",
 *      in="query",
 *      description="optional search term for source codes, case insensitive",
 *      example="wu",
 *      @OA\Schema(type="string")
 *  ),
 *  @OA\Parameter(
 *      name="coll",
 *      in="query",
 *      description="optional search term for collector(s), case insensitive",
 *      example="rainer",
 *      @OA\Schema(type="string")
 *  ),
 *  @OA\Parameter(
 *      name="type",
 *      in="query",
 *      description="optional switch to search for type records only, defaults to 0",
 *      example="1",
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Parameter(
 *      name="sort",
 *      in="query",
 *      description="optional sorting of results, seperated by commas, '-' as first character changes sorting to DESC, possible items are sciname (scientific name), coll (collector(s)), ser (series), num (collectors number), herbnr (herbarium number), defaults to sciname,herbnr",
 *      example="coll,num",
 *      @OA\Schema(type="string")
 *  ),
 *  @OA\Response(response="200", description="successful operation"),
 * )
 */
$app->get('/specimens/search', function (Request $request, Response $response)
{
//    $this->logger->addInfo("called specimens/search ");

    $mapper = new ObjectsMapper($this->db);

    $params = $request->getQueryParams();
    $taxonIDList = array();
    if (!empty($params['term'])) {
        $mapperSciNames = new JACQscinamesMapper($this->db, array('jacq_input_services' => $this->get('settings')['jacq_input_services'],
                                                                  'apikey' => $this->get('settings')['APIKEY']));
        $scinamesList = $mapperSciNames->searchScientificName(trim(filter_var($params['term'], FILTER_SANITIZE_STRING)));
        foreach ($scinamesList as $item) {
            $taxonIDList[] = $item['taxonID'];
        }
    }
    $data = $mapper->searchSpecimensList($params, $taxonIDList);

    $jsonResponse = $response->withJson($data);
    return $jsonResponse;

});

/**
 * @OA\Get(
 *  path="/specimens/{specimenID}",
 *  summary="get the properties of a specimen",
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
$app->get('/specimens/{specimenID}', function (Request $request, Response $response, array $args)
{
//    $this->logger->addInfo("called specimens ");

    $mapper = new ObjectsMapper($this->db);

    $data = $mapper->getSpecimenData(intval(filter_var($args['specimenID'], FILTER_SANITIZE_NUMBER_INT)));

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
