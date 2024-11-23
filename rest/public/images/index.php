<?php
require __DIR__ . '/../../vendor/autoload.php';

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

        // Monolog settings
        'logger' => [
            'name' => 'slim-app',
            'path' => __DIR__ . '/../../logs/images.log',
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
$container['phpErrorHandler'] = function ($container)
{
    return function ($request, $response, $exception) use ($container) {
        $data = [
            'message' => $exception->getMessage()
        ];
        $jsonResponse = $response->withStatus(500)->withJson($data);
        return $jsonResponse;
    };
};

//Override the default Not Found Handler
unset($container['notFoundHandler']);
$container['notFoundHandler'] = function ($c)
{
    return function ($request, $response) use ($c) {
        $response = new \Slim\Http\Response(404);
        return $response->withJson(array("error" => "no image available"));
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
 *  path="/images/show/{specimenID}",
 *  tags={"images"},
 *  summary="get the uri to show the first image of a given specimen-ID with a redirect (303)",
 *  @OA\Parameter(
 *      name="specimenID",
 *      in="path",
 *      description="ID of specimen",
 *      required=true,
 *      example=1739342,
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Parameter(
 *      name="imageNr",
 *      in="path",
 *      description="image number (defaults to 0=first image)",
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Parameter(
 *      name="withredirect",
 *      in="query",
 *      description="optional switch to answer with a redirect (303) to the latest link (if it exists) instead of '200', defaults to 0 (no redirect)",
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Response(response="200", description="successful operation"),
 * )
 */
$app->get('/show/{specimenID}[/{imageNr}]', function (Request $request, Response $response, array $args)
{
//    $this->logger->addInfo("called show ");

    $params = $request->getQueryParams();
    $mapper = new ImageLinkMapper($this->db, intval(filter_var($args['specimenID'], FILTER_SANITIZE_NUMBER_INT)));

    $imageLink = $mapper->getShowLink(intval($args['imageNr'] ?? 0));
    if ($imageLink) {
        $data = array('link' => $imageLink);
        if (!empty($params['withredirect'])) {
            return $response->withJson($data)->withRedirect($imageLink, 303);
        } else {
            return $response->withJson($data);
        }
    } else {
        $handler = $this->notFoundHandler; // handle using the default Slim page not found handler
        return $handler($request, $response);
    }
});

/**
 * @OA\Get(
 *  path="/images/download/{specimenID}",
 *  tags={"images"},
 *  summary="get the uri to download the first image of a given specimen-ID with a redirect (303)",
 *  @OA\Parameter(
 *      name="specimenID",
 *      in="path",
 *      description="ID of specimen",
 *      required=true,
 *      example=1739342,
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Parameter(
 *      name="imageNr",
 *      in="path",
 *      description="image number (defaults to 0=first image)",
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Parameter(
 *      name="withredirect",
 *      in="query",
 *      description="optional switch to answer with a redirect (303) to the latest link (if it exists) instead of '200', defaults to 0 (no redirect)",
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Response(response="200", description="successful operation"),
 * )
 */
$app->get('/download/{specimenID}[/{imageNr}]', function (Request $request, Response $response, array $args)
{
//    $this->logger->addInfo("called download ");

    $params = $request->getQueryParams();
    $mapper = new ImageLinkMapper($this->db, intval(filter_var($args['specimenID'], FILTER_SANITIZE_NUMBER_INT)));

    $imageLink = $mapper->getDownloadLink(intval($args['imageNr'] ?? 0));
    if ($imageLink) {
        $data = array('link' => $imageLink);
        if (!empty($params['withredirect'])) {
            return $response->withJson($data)->withRedirect($imageLink, 303);
        } else {
            return $response->withJson($data);
        }
    } else {
        $handler = $this->notFoundHandler; // handle using the default Slim page not found handler
        return $handler($request, $response);
    }
});

/**
 * @OA\Get(
 *  path="/images/europeana/{specimenID}",
 *  tags={"images"},
 *  summary="get the uri to download the first image of a given specimen-ID with resolution 1200,x with a redirect (303)",
 *  @OA\Parameter(
 *      name="specimenID",
 *      in="path",
 *      description="ID of specimen",
 *      required=true,
 *      example=1739342,
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Parameter(
 *      name="imageNr",
 *      in="path",
 *      description="image number (defaults to 0=first image)",
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Parameter(
 *      name="withredirect",
 *      in="query",
 *      description="optional switch to answer with a redirect (303) to the latest link (if it exists) instead of '200', defaults to 0 (no redirect)",
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Response(response="200", description="successful operation"),
 * )
 */
$app->get('/europeana/{specimenID}[/{imageNr}]', function (Request $request, Response $response, array $args)
{
//    $this->logger->addInfo("called europeana ");

    $params = $request->getQueryParams();
    $mapper = new ImageLinkMapper($this->db, intval(filter_var($args['specimenID'], FILTER_SANITIZE_NUMBER_INT)));

    $imageLink = $mapper->getEuropeanaLink(intval($args['imageNr'] ?? 0));
    if ($imageLink) {
        $data = array('link' => $imageLink);
        if (!empty($params['withredirect'])) {
            return $response->withJson($data)->withRedirect($imageLink, 303);
        } else {
            return $response->withJson($data);
        }
    } else {
        $handler = $this->notFoundHandler; // handle using the default Slim page not found handler
        return $handler($request, $response);
    }
});

/**
 * @OA\Get(
 *  path="/images/thumb/{specimenID}",
 *  tags={"images"},
 *  summary="get the uri to download the first image of a given specimen-ID with resolution 160,x with a redirect (303)",
 *  @OA\Parameter(
 *      name="specimenID",
 *      in="path",
 *      description="ID of specimen",
 *      required=true,
 *      example=1739342,
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Parameter(
 *      name="imageNr",
 *      in="path",
 *      description="image number (defaults to 0=first image)",
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Parameter(
 *      name="withredirect",
 *      in="query",
 *      description="optional switch to answer with a redirect (303) to the latest link (if it exists) instead of '200', defaults to 0 (no redirect)",
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Response(response="200", description="successful operation"),
 * )
 */
$app->get('/thumb/{specimenID}[/{imageNr}]', function (Request $request, Response $response, array $args)
{
//    $this->logger->addInfo("called thumb ");

    $params = $request->getQueryParams();
    $mapper = new ImageLinkMapper($this->db, intval(filter_var($args['specimenID'], FILTER_SANITIZE_NUMBER_INT)));

    $imageLink = $mapper->getThumbLink(intval($args['imageNr'] ?? 0));
    if ($imageLink) {
        $data = array('link' => $imageLink);
        if (!empty($params['withredirect'])) {
            return $response->withJson($data)->withRedirect($imageLink, 303);
        } else {
            return $response->withJson($data);
        }
    } else {
        $handler = $this->notFoundHandler; // handle using the default Slim page not found handler
        return $handler($request, $response);
    }
});

/**
 * @OA\Get(
 *  path="/images/list/{specimenID}",
 *  tags={"images"},
 *  summary="get a list of all image-uris of a given specimen-ID",
 *  @OA\Parameter(
 *      name="specimenID",
 *      in="path",
 *      description="ID of specimen",
 *      required=true,
 *      example=1739342,
 *      @OA\Schema(type="integer")
 *  ),
 *  @OA\Response(response="200", description="successful operation"),
 * )
 */
$app->get('/list/{specimenID}', function (Request $request, Response $response, array $args)
{
//    $this->logger->addInfo("called list ");

    $mapper = new ImageLinkMapper($this->db, intval(filter_var($args['specimenID'], FILTER_SANITIZE_NUMBER_INT)));

    $data = $mapper->getList();

    $jsonResponse = $response->withJson($data);
    return $jsonResponse;
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
