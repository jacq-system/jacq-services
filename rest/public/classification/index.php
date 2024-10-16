<?php
require __DIR__ . '/../../vendor/autoload.php';

use Slim\Http\Request;
use Slim\Http\Response;
use function OpenApi\scan;
//        "zircote/swagger-php": "^3.1"


/**
 * @OA\Info(title="JACQ Webservices: classification", version="0.1")
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
            'path' => __DIR__ . '/../../logs/classification.log',
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
 *  path="/references/{referenceType}[/{referenceID}]",
 *  summary="Fetch a list of all references (which have a classification attached) or a single reference",
 *  @OA\Parameter(
 *      name="referenceType",
 *      in="path",
 *      description="Type of reference (citation, person, service, specimen, periodical)",
 *      required=true,
 *      example="periodical",
 *      @OA\Schema(type="string")
 *  ),
 *  @OA\Parameter(
 *      name="referenceID",
 *      in="path",
 *      description="ID of reference",
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Response(response="200", description="successful operation"),
 * )
 */
$app->get('/references/{referenceType}[/{referenceID}]', function (Request $request, Response $response, array $args)
{
//    $this->logger->addInfo("called references " . var_export($args, true));

    $mapper = new ClassificationMapper($this->db);
    if (!empty($args['referenceID'])) {
        $references = $mapper->getReferences(trim(filter_var($args['referenceType'], FILTER_SANITIZE_STRING)),
                                             intval($args['referenceID']));
    } else {
        $references = $mapper->getReferences(trim(filter_var($args['referenceType'], FILTER_SANITIZE_STRING)));
    }
    $jsonResponse = $response->withJson($references);
    return $jsonResponse;
});

/**
 * @OA\Get(
 *  path="/nameReferences/{taxonID}",
 *  summary="Return (other) references for this name which include them in their classification",
 *  @OA\Parameter(
 *      name="taxonID",
 *      in="path",
 *      description="ID of name to look for",
 *      required=true,
 *      example="46163",
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Parameter(
 *      name="excludeReferenceId",
 *      in="query",
 *      description="optional Reference-ID to exclude (to avoid returning the 'active' reference)",
 *      example="31070",
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Parameter(
 *      name="insertSeries",
 *      in="query",
 *      description="optional ID of cication-Series to insert",
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Response(response="200", description="successful operation"),
 * )
 */
$app->get('/nameReferences/{taxonID}', function (Request $request, Response $response, array $args)
{
//    $this->logger->addInfo("called references ");

    $mapper = new ClassificationMapper($this->db);
    $nameReferences = $mapper->getNameReferences(intval($args['taxonID']),
                                                 intval($request->getQueryParam('excludeReferenceId')),
                                                 intval($request->getQueryParam('insertSeries')));
    $jsonResponse = $response->withJson($nameReferences);
    return $jsonResponse;
});

/**
 * @OA\Get(
 *  path="/children/{referenceType}/{referenceId}",
 *  summary="Get classification children of a given taxonID according to a given reference",
 *  @OA\Parameter(
 *      name="referenceType",
 *      in="path",
 *      description="Type of reference (citation, person, service, specimen, periodical)",
 *      required=true,
 *      example="citation",
 *      @OA\Schema(type="string")
 *  ),
 *  @OA\Parameter(
 *      name="referenceId",
 *      in="path",
 *      description="ID of reference",
 *      required=true,
 *      example="31070",
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Parameter(
 *      name="taxonID",
 *      in="query",
 *      description="optional ID of taxon name",
 *      example="233647",
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Parameter(
 *      name="insertSeries",
 *      in="query",
 *      description="optional ID of cication-Series to insert",
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Response(response="200", description="successful operation"),
 * )
 */
$app->get('/children/{referenceType}/{referenceId}', function (Request $request, Response $response, array $args)
{
//    $this->logger->addInfo("called children " . intval($request->getQueryParam('taxonID')));

    $mapper = new ClassificationMapper($this->db);
    $children = $mapper->getChildren(trim(filter_var($args['referenceType'], FILTER_SANITIZE_STRING)),
                                     intval($args['referenceId']),
                                     intval($request->getQueryParam('taxonID')),
                                     intval($request->getQueryParam('insertSeries')));
    $jsonResponse = $response->withJson($children);
    return $jsonResponse;
});

/**
 * @OA\Get(
 *  path="/synonyms/{referenceType}/{referenceId}/{taxonID}",
 *  summary="fetch synonyms (and basionym) for a given taxonID, according to a given reference",
 *  @OA\Parameter(
 *      name="referenceType",
 *      in="path",
 *      description="Type of reference (citation, person, service, specimen, periodical)",
 *      required=true,
 *      @OA\Schema(type="string")
 *  ),
 *  @OA\Parameter(
 *      name="referenceId",
 *      in="path",
 *      description="ID of reference",
 *      required=true,
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Parameter(
 *      name="taxonID",
 *      in="path",
 *      description="ID of taxon name",
 *      required=true,
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Parameter(
 *      name="insertSeries",
 *      in="query",
 *      description="optional ID of cication-Series to insert",
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Response(response="200", description="successful operation"),
 * )
 */
$app->get('/synonyms/{referenceType}/{referenceId}/{taxonID}', function (Request $request, Response $response, array $args)
{
//    $this->logger->addInfo("called synonyms. args=" . var_export($args, true));

    $mapper = new ClassificationMapper($this->db);
    $synonyms = $mapper->getSynonyms(trim(filter_var($args['referenceType'], FILTER_SANITIZE_STRING)),
                                     intval($args['referenceId']),
                                     intval($args['taxonID']),
                                     intval($request->getQueryParam('insertSeries')));
    $jsonResponse = $response->withJson($synonyms);
    return $jsonResponse;
});

/**
 * @OA\Get(
 *  path="/parent/{referenceType}/{referenceId}/{taxonID}",
 *  summary="Get the parent entry of a given reference",
 *  @OA\Parameter(
 *      name="referenceType",
 *      in="path",
 *      description="Type of reference (citation, person, service, specimen, periodical)",
 *      required=true,
 *      @OA\Schema(type="string")
 *  ),
 *  @OA\Parameter(
 *      name="referenceId",
 *      in="path",
 *      description="ID of reference",
 *      required=true,
 *      @OA\Schema(type="integer")
 *  ),
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
$app->get('/parent/{referenceType}/{referenceId}/{taxonID}', function (Request $request, Response $response, array $args)
{
//    $this->logger->addInfo("called parent. args=" . var_export($args, true));

    $mapper = new ClassificationMapper($this->db);
    $synonyms = $mapper->getParent(trim(filter_var($args['referenceType'], FILTER_SANITIZE_STRING)),
                                   intval($args['referenceId']),
                                   intval($args['taxonID']));
    $jsonResponse = $response->withJson($synonyms);
    return $jsonResponse;
});

/**
 * @OA\Get(
 *  path="/numberOfChildrenWithChildrenCitation/{referenceId}",
 *  summary="Get number of classification children who have children themselves of a given taxonID according to a given reference of type citation",
 *  @OA\Parameter(
 *      name="referenceId",
 *      in="path",
 *      description="ID of reference",
 *      required=true,
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Parameter(
 *      name="taxonID",
 *      in="query",
 *      description="optional ID of taxon name",
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Response(response="200", description="successful operation"),
 * )
 */
$app->get('/numberOfChildrenWithChildrenCitation/{referenceId}', function (Request $request, Response $response, array $args)
{
//    $this->logger->addInfo("called references ");

    $mapper = new ClassificationMapper($this->db);
    $number = $mapper->getNumberOfChildrenWithChildrenCitation(intval($args['referenceId']), intval($request->getQueryParam('taxonID')));
    $jsonResponse = $response->withJson($number);
    return $jsonResponse;
});

/**
 * @OA\Get(
 *  path="/periodicalStatistics/{referenceId}",
 *  summary="Get statistics information of a given reference",
 *  @OA\Parameter(
 *      name="referenceId",
 *      in="path",
 *      description="ID of reference",
 *      required=true,
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Response(response="200", description="successful operation"),
 * )
 */
$app->get('/periodicalStatistics/{referenceId}', function (Request $request, Response $response, array $args)
{
//    $this->logger->addInfo("called periodicalStatistics ");

    $mapper = new ClassificationMapper($this->db);
    $statistics = $mapper->getPeriodicalStatistics(intval($args['referenceId']));
    $jsonResponse = $response->withJson($statistics);
    return $jsonResponse;
});

/**
 * @OA\Get(
 *  path="/download/{referenceType}/{referenceId}",
 *  summary="Get an array, filled with header and data for download",
 *  @OA\Parameter(
 *      name="referenceType",
 *      in="path",
 *      description="Type of reference (citation, person, service, specimen, periodical)",
 *      required=true,
 *      @OA\Schema(type="string")
 *  ),
 *  @OA\Parameter(
 *      name="referenceId",
 *      in="path",
 *      description="ID of reference",
 *      required=true,
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Parameter(
 *      name="scientificNameId",
 *      in="query",
 *      description="optional ID of scientific name",
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Parameter(
 *      name="hideScientificNameAuthors",
 *      in="query",
 *      description="hide authors name in scientific name (optional, default = use database)",
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Response(response="200", description="successful operation"),
 * )
 */
$app->get('/download/{referenceType}/{referenceId}', function (Request $request, Response $response, array $args)
{
//    $this->logger->addInfo("called download ");

    $mapper = new ClassificationDownloadMapper($this->db, array('jacq_input_services' => $this->get('settings')['jacq_input_services'],
                                                                'apikey' => $this->get('settings')['APIKEY'],
                                                                'classifications_license' => $this->get('settings')['classifications_license']));
    $data = $mapper->createDownload(trim(filter_var($args['referenceType'], FILTER_SANITIZE_STRING)),
                                    intval($args['referenceId']),
                                    intval($request->getQueryParam('scientificNameId')),
                                    filter_var($request->getQueryParam('hideScientificNameAuthors'), FILTER_SANITIZE_STRING));
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
