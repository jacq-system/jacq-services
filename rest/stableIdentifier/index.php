<?php
require __DIR__ . '/../vendor/autoload.php';

use Slim\Http\Request;
use Slim\Http\Response;
use function OpenApi\scan;
//        "zircote/swagger-php": "^3.1"


/**
 * @OA\Info(
 *     title="JACQ Webservices: stableIdentifier",
 *     version="0.1"
 * )
 */
include __DIR__ . '/../inc/openApiServer.php';

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
            'path' => __DIR__ . '/../logs/stableIdentifier.log',
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
 *  path="/stableIdentifier/sid/{specimenID}",
 *  summary="Get specimen-id, valid stable identifier and all stable identifiers of a given specimen-id",
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
$app->get('/sid/{specimenID}', function (Request $request, Response $response, array $args)
{
//    $this->logger->addInfo("called sid ");

    $mapper = new StableIdentifierMapper($this->db);
    $specimenID = intval(filter_var($args['specimenID'], FILTER_SANITIZE_NUMBER_INT));
    $sids = $mapper->getAllSid($specimenID);
    if ($sids) {
        $data = array('specimenID'             => intval($specimenID),
                      'stableIdentifierLatest' => $sids['latest'],
                      'stableIdentifierList'   => $sids['list']);
    } else {
        $data = array();
    }
    $jsonResponse = $response->withJson($data);
    return $jsonResponse;
});

/**
 * @OA\Get(
 *  path="/stableIdentifier/resolve/{sid}",
 *  summary="Get specimen-id, valid stable identifier and all stable identifiers of a given stable idnetifier. Answers with 303 instead of 200 if parameter withredirect is given",
 *  @OA\Parameter(
 *      name="sid",
 *      in="path",
 *      description="stable identifier of specimen",
 *      required=true,
 *      @OA\Schema(type="string")
 *  ),
 *  @OA\Parameter(
 *      name="withredirect",
 *      in="query",
 *      description="optional switch to answer with a redirect (303) to the latest link (if it exists) instead of "200", defaults to 0 (no redirect)",
 *      example="1",
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Response(response="200", description="successful operation"),
 * )
 */
$app->get('/resolve/{sid:.*}', function (Request $request, Response $response, array $args)
{
//    $this->logger->addInfo("called resolve ");

    $params = $request->getQueryParams();
    $mapper = new StableIdentifierMapper($this->db);
    $specimenID = $mapper->getSpecimenID(filter_var($args['sid'], FILTER_SANITIZE_STRING));
    if ($specimenID) {
        $sids = $mapper->getAllSid($specimenID);
        $data = array('specimenID'             => intval($specimenID),
                      'stableIdentifierLatest' => $sids['latest'],
                      'stableIdentifierList'   => $sids['list']);
    } else {
        $data = array();
    }
    if (!empty($params['withredirect']) && !empty($data['stableIdentifierLatest']['link'])) {
        return $response->withJson($data)->withRedirect($data['stableIdentifierLatest']['link'], 303);
    } elseif (empty($data)) {
        return $response->withStatus(404, "Stable Identifier does not exist.");
    } else {
        return $response->withJson($data);
    }
});

/**
 * @OA\Get(
 *  path="/stableIdentifier/multi",
 *  summary="Get all entries with more than one stable identifier per specimen-ID",
 *  @OA\Parameter(
 *      name="page",
 *      in="query",
 *      description="optional number of page to be returned (default=first page)",
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Parameter(
 *      name="entriesPerPage",
 *      in="query",
 *      description="optional number entries per page (default=50)",
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Parameter(
 *      name="sourceID",
 *      in="query",
 *      description="optional ID of source to check (default=all sources)",
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Response(response="200", description="successful operation"),
 * )
 */
$app->get('/multi', function (Request $request, Response $response, array $args)
{
//    $this->logger->addInfo("called multi ");

    $mapper = new StableIdentifierMapper($this->db);
    $sourceID = intval($request->getQueryParam('sourceID'));
    if ($sourceID) {
        $data = $mapper->getMultipleEntriesFromSource($sourceID);
    } else {
        $data = $mapper->getMultipleEntries(intval($request->getQueryParam('page')), intval($request->getQueryParam('entriesPerPage')));
    }
    $jsonResponse = $response->withJson($data);
    return $jsonResponse;
});

/**
 * @OA\Get(
 *  path="/stableIdentifier/errors",
 *  summary="get a list of all errors which prevent the generation of stable identifier",
 *  @OA\Parameter(
 *      name="sourceID",
 *      in="query",
 *      description="optional ID of source to check (default=all sources)",
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Response(response="200", description="successful operation"),
 * )
 */
$app->get('/errors', function (Request $request, Response $response, array $args)
{
//    $this->logger->addInfo("called errors ");

    $mapper = new StableIdentifierMapper($this->db);
    $data = $mapper->getEntriesWithErrors(intval($request->getQueryParam('sourceID')));
    $jsonResponse = $response->withJson($data);
    return $jsonResponse;
});

/**
 * @OA\Get(
 *     path="/stableIdentifier/openapi",
 *     tags={"documentation"},
 *     summary="OpenAPI JSON File that describes the API",
 *     @OA\Response(response="200", description="OpenAPI Description File"),
 * )
 */
$app->get('/openapi', function ($request, $response, $args)
{
//    $swagger = scan(__DIR__);
    $swagger = \OpenApi\Generator::scan([__DIR__, __DIR__ . '/../inc']);
    $jsonResponse = $response->withJson($swagger);
    return $jsonResponse;
});

$app->get('/description', function($request, $response, $args) {
    return file_get_contents('description.html');
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
