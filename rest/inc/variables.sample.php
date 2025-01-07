<?php
$_CONFIG['DATABASES']['HERBARINPUT'] = array(
    "host" => "localhost",
    "db"   => "herbarinput",
    "user" => "user",
    "pass" => "password"
);
$_CONFIG['DATABASES']['JACQINPUT'] = array(
    "host" => "localhost",
    "db"   => "jacq_input",
    "user" => "user",
    "pass" => "password"
);
$_CONFIG['displayErrorDetails'] = false;
$_CONFIG['JACQ_INPUT_SERVICES'] = "http://url-to-input-service/";
$_CONFIG['APIKEY'] = "api-key";

$_CONFIG['classifications_license'] = 'CC-BY-SA';

$_CONFIG['externalScinameServices'] = [
    [
        'code'      => 'gbif',
        'serviceID' => 51,
        'url'       => "https://api.gbif.org/v1/species/search?datasetKey=d7dddbf4-2cf0-4f39-9b2a-bb099caae36c&q="
    ],[
        'code'      => 'wfo',
        'serviceID' => 57,
        'url'       => "https://list.worldfloraonline.org/matching_rest.php?input_string="
    ],[
        'code'      => 'worms',
        'serviceID' => 58,
        'url'       => "https://www.marinespecies.org/rest/AphiaRecordsByName/"
    ]
];
