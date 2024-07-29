<?php
require __DIR__ . '/vendor/autoload.php';

/************************
 * include all settings *
 ************************/
include __DIR__ . '/inc/variables.php';

$dbLink = new mysqli($_CONFIG['DATABASES']['HERBARINPUT']['host'],
                     $_CONFIG['DATABASES']['HERBARINPUT']['user'],
                     $_CONFIG['DATABASES']['HERBARINPUT']['pass'],
                     $_CONFIG['DATABASES']['HERBARINPUT']['db']);
$dbLink->set_charset('utf8');

$params = array();
// get all possible parameters
foreach ($_POST as $key => $value) {
    $params[$key] = filter_input(INPUT_POST, $key, FILTER_SANITIZE_URL);
}
foreach ($_GET as $key => $value) {
    $params[$key] = filter_input(INPUT_GET, $key, FILTER_SANITIZE_URL);
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Content-Type: text/xml;charset=UTF-8');

$response = new Jacq\Oai\Response($dbLink, $params);
echo $response->getXml()->flush();
