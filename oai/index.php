<?php

use Jacq\Settings;

require __DIR__ . '/vendor/autoload.php';

$settings = Settings::Load();

$dbLink = new mysqli($settings->get('DATABASES', 'HERBARINPUT', 'host'),
                     $settings->get('DATABASES', 'HERBARINPUT', 'user'),
                     $settings->get('DATABASES', 'HERBARINPUT', 'pass'),
                     $settings->get('DATABASES', 'HERBARINPUT', 'db'));
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
