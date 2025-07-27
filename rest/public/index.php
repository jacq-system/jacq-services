<?php
require __DIR__ . '/../vendor/autoload.php';

use Slim\Http\Request;
use Slim\Http\Response;
use OpenApi\Generator;
//        "zircote/swagger-php": "^3.1"


/**
 * @OA\Info(
 *     title="JACQ Webservices",
 *     version="1.0"
 * )
 * @OA\Tag(
 *     name="iiif",
 *     description="get IIIF manifests",
 *     @OA\ExternalDocumentation(
 *         description="find out more...",
 *         url="https://services.jacq.org/jacq-services/rest/iiif/description.html"
 *     )
 * )
 * @OA\Tag(
 * *     name="images",
 * *     description="get links to images",
 * * )
 * @OA\Tag(
 *     name="objects",
 *     description="get or find specimens",
 *     @OA\ExternalDocumentation(
 *         description="find out more...",
 *         url="https://services.jacq.org/jacq-services/rest/objects/description.html"
 *     )
 * )
 * @OA\Tag(
 *     name="JACQscinames",
 *     description="get or find scientific names",
 *     @OA\ExternalDocumentation(
 *         description="find out more...",
 *         url="https://services.jacq.org/jacq-services/rest/JACQscinames/description.html"
 *     )
 * )
 * @OA\Tag(
 *     name="externalScinames",
 *     description="use external services to find scientific names",
 * )
 * @OA\Tag(
 *     name="stableIdentifier",
 *     description="handle stable identifiers",
 *     @OA\ExternalDocumentation(
 *         description="find out more...",
 *         url="https://services.jacq.org/jacq-services/rest/stableIdentifier/description.html"
 *     )
 * )
 * @OA\Tag(
 *     name="geo",
 *     description="JACQ geoservice",
 *     @OA\ExternalDocumentation(
 *         description="find out more...",
 *         url="https://services.jacq.org/jacq-services/rest/geo/description.html"
 *     )
 * )
 * @OA\Tag(
 *     name="autocomplete",
 *     description="find fitting scientific names and return them",
 *     @OA\ExternalDocumentation(
 *         description="find out more...",
 *         url="https://services.jacq.org/jacq-services/rest/autocomplete/description.html"
 *     )
 * )
 * @OA\Tag(
 *     name="classification",
 *     @OA\ExternalDocumentation(
 *         description="find out more...",
 *         url="https://services.jacq.org/jacq-services/rest/classification/description.html"
 *     )
 * )
 * @OA\Tag(
 *     name="statistics"
 * )
 * OA\Tag(
 *     name="livingplants"
 * )
 */

/***********************
 * Instantiate the app *
 ***********************/
$app = new \Slim\App();



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
$app->get('/openapi', function (Request $request, Response $response)
{
    $swagger = Generator::scan([__DIR__, __DIR__ . '/../inc']);
    $jsonResponse = $response->withJson($swagger);
    return $jsonResponse;
});
$app->get('/doc', function (Request $request, Response $response) {
    return $response->withRedirect('description/', 307);
});
$app->get('/documentation', function (Request $request, Response $response) {
    return $response->withRedirect('description/', 307);
});




/***********
 * Run app *
 ***********/
$app->run();
