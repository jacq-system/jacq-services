<?php
// database access credentials
$_CONFIG['DATABASES']['HERBARINPUT'] = array(
    "host" => "localhost",
    "db"   => "herbarinput",
    "user" => "user",
    "pass" => "password"
);

// baseURL of this service
$_CONFIG['BASEURL'] = 'https://services.jacq.org/jacq-services/oai/';

// base URL of JACQ images service
$_CONFIG['IMAGESBASEURL'] = 'https://services.jacq.org/jacq-services/rest/images/';

// identifier prefix for "ListIdentifiers"
$_CONFIG['IDENTIFIER_PREFIXJACQ'] = 'oai:jacq.org:';

// MetadataIDs of all sources allowed for this service to use
$_CONFIG['SETSALLOWED'] = [1, 4, 5, 6, 55];
