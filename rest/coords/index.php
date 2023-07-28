<?php
require __DIR__ . '/../vendor/autoload.php';

use Slim\Http\Request;
use Slim\Http\Response;
use function OpenApi\scan;
use PHPCoord\CoordinateReferenceSystem\Geographic2D;
use PHPCoord\Point\GeographicPoint;
use PHPCoord\Point\UTMPoint;
use PHPCoord\UnitOfMeasure\Angle\Degree;
use PHPCoord\UnitOfMeasure\Length\Metre;
//        "zircote/swagger-php": "^3.1"


/**
 * @OA\Info(title="JACQ Webservices: images", version="0.1")
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
            'path' => __DIR__ . '/../logs/coords.log',
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
 *  path="/convert",
 *  summary="convert one system (e.g. Coordinates) into another (e.g. UTM), using WGS 84",
 *  @OA\Parameter(
 *      name="lat",
 *      in="query",
 *      description="convert from latitude/longitude. This is latitude, parameter 'lon' is now mandatory",
 *      example="48.21",
 *      @OA\Schema(type="float")
 *  ),
 *  @OA\Parameter(
 *      name="lon",
 *      in="query",
 *      description="convert from latitude/longitude. This is longitude, parameter 'lat' is now mandatory",
 *      example="16.37",
 *      @OA\Schema(type="float")
 *  ),
 *  @OA\Parameter(
 *      name="utm",
 *      in="query",
 *      description="convert from UTM",
 *      example="33U 601779 5340549",
 *      @OA\Schema(type="string")
 *  ),
 *  @OA\Response(response="200", description="successful operation"),
 * )
 */
$app->get('/convert', function (Request $request, Response $response)
{
//    $this->logger->addInfo("called convert ");

    $params = $request->getQueryParams();
    if (isset($params['lat']) && isset($params['lon'])) {  // from lat/lon
        $lat = floatval($params['lat']);
        $from = GeographicPoint::create(
            Geographic2D::fromSRID(Geographic2D::EPSG_WGS_84),
            new Degree($lat),
            new Degree(floatval($params['lon'])),
            null
        );
        $to = $from->asUTMPoint();
        if ($lat < -32) {
            $band = chr(floor($lat / 8) + ord('M')); // band is 'H' or below
        } elseif ($lat < 8) {
            $band = chr(floor($lat / 8) + ord('N')); // band is between 'J' and 'N'
        } else {
            $band = chr(floor($lat / 8) + ord('O')); // band is 'P' or above
        }
        $data = array(
            'zone'        => $to->getZone(),
            'hemisphere'  => $to->getHemisphere(),
            'easting'     => (int)$to->getEasting()->getValue(),
            'northing'    => (int)$to->getNorthing()->getValue(),
            'string'      => $to->getZone() . $band . ' ' . (int) $to->getEasting()->getValue() . ' ' . (int) $to->getNorthing()->getValue()
        );
    } elseif (isset($params['utm'])) {  // from UTM
        $parts = explode(' ', preg_replace('/\s+/', ' ', trim($params['utm'])));
        if (is_numeric($parts[1])) {
            $hemisphere = (substr($parts[0], 2) >= 'N') ? UTMPoint::HEMISPHERE_NORTH : UTMPoint::HEMISPHERE_SOUTH;
            $easting = $parts[1];
            $northing = $parts[2];
        } else {
            $hemisphere = ($parts[1] == 'S') ? UTMPoint::HEMISPHERE_SOUTH : UTMPoint::HEMISPHERE_NORTH;
            $easting = $parts[2];
            $northing = $parts[3];
        }
        $from = new UTMPoint(
            Geographic2D::fromSRID(Geographic2D::EPSG_WGS_84),
            new Metre($easting),
            new Metre($northing),
            intval($parts[0]),
            $hemisphere
        );
        $to = $from->asGeographicPoint();
        $data = array(
            'lat'    => $to->getLatitude()->getValue(),
            'lon'    => $to->getLongitude()->getValue(),
            'string' => (string)$to
        );
    } else {
        $data = array('error' => "nothing to do");
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
$app->get('/openapi', function (Request $request, Response $response) {
    $swagger = scan(__DIR__);
    $jsonResponse = $response->withJson($swagger);
    return $jsonResponse;
});

//$app->get('/description', function(Request $request, Response $response) {
//    return file_get_contents('description.html');
//});

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
