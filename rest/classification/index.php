<?php
require __DIR__ . '/../vendor/autoload.php';

use Slim\Http\Request;
use Slim\Http\Response;
//use function OpenApi\scan;
//        "zircote/swagger-php": "^3.1"


/**
 * @OA\Info(title="JACQ Webservices", version="0.1")
 */

/************************
 * include all settings *
 ************************/
include __DIR__ . '/../inc/variables.php';
$settings = [
    'settings' => [
        'displayErrorDetails' => true, // set to false in production
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
            'path' => __DIR__ . '/../logs/classification.log',
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
//$container['phpErrorHandler'] = function ($container) {
//    return function ($request, $response, $exception) use ($container) {
//        $data = [
//            'message' => $exception->getMessage()
//        ];
//        $jsonResponse = $response->withStatus(500)->withJson($data);
//        return $jsonResponse;
//    };
//};



/*******************
 * Register routes *
 *******************/
/**
 * @OA\Get(
 *     path="/references/{referenceType}",
 *     tags={"references"},
 *     summary="Fetch a list of all references (which have a classification attached)",
 *     @OA\Parameter(
 *         name="referenceType",
 *         in="path",
 *         description="Type of references to return (citation, person, service, specimen, periodical)",
 *         required=true,
 *         @OA\Schema(
 *             type="string"
 *         )
 *     ),
 *     @OA\Response(response="200", description="successful operation"),
 * )
 */
$app->get('/references/{referenceType}', function (Request $request, Response $response, array $args)
{
//    $this->logger->addInfo("called references ");

    $mapper = new ClassificationMapper($this->db);
    $references = $mapper->getReferences(trim(filter_var($args['referenceType'], FILTER_SANITIZE_STRING)));
    $jsonResponse = $response->withJson($references);
    return $jsonResponse;
});

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

$app->get('/numberOfChildrenWithChildrenCitation/{referenceId}', function (Request $request, Response $response, array $args)
{
//    $this->logger->addInfo("called references ");

    $mapper = new ClassificationMapper($this->db);
    $number = $mapper->getNumberOfChildrenWithChildrenCitation(intval($args['referenceId']), intval($request->getQueryParam('taxonID')));
    $jsonResponse = $response->withJson($number);
    return $jsonResponse;
});

$app->get('/periodicalStatistics/{referenceId}', function (Request $request, Response $response, array $args)
{
//    $this->logger->addInfo("called periodicalStatistics ");

    $mapper = new ClassificationMapper($this->db);
    $statistics = $mapper->getPeriodicalStatistics(intval($args['referenceId']));
    $jsonResponse = $response->withJson($statistics);
    return $jsonResponse;
});

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
//$app->get('/openapi', function ($request, $response, $args) {
//    $swagger = scan(__DIR__);
//    $jsonResponse = $response->withJson($swagger);
//    return $jsonResponse;
//});

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
