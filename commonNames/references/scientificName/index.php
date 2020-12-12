<?php
include __DIR__ . '/inc/variables.php';

$baseUrl = filter_input(INPUT_SERVER, 'REQUEST_SCHEME', FILTER_SANITIZE_STRING) . "://"
         . filter_input(INPUT_SERVER, 'HTTP_HOST', FILTER_SANITIZE_STRING)
         . dirname(filter_input(INPUT_SERVER, 'SCRIPT_NAME', FILTER_SANITIZE_STRING)) . '/';

$scientific_name_id = intval(filter_input(INPUT_GET, 'scientific_name_id', FILTER_SANITIZE_NUMBER_INT));

// get the scientific name
$db = mysqli_connect($_CONFIG['DATABASES']['OPENUP']['host'],
                     $_CONFIG['DATABASES']['OPENUP']['user'],
                     $_CONFIG['DATABASES']['OPENUP']['pass'],
                     $_CONFIG['DATABASES']['OPENUP']['db']);
$row = $db->query("SELECT name FROM tbl_scientific_name_cache WHERE id = $scientific_name_id")->fetch_assoc();
$name = ($row) ? $row['name'] : '';

// get all common names
$service_url = $_CONFIG['JACQ_NAMES_SERVICE'] . '?query=' . rawurlencode($name);
$curl = curl_init($service_url);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
$curl_response = curl_exec($curl);
curl_close($curl);
$commonNames = json_decode($curl_response, true);
$commonName_results = $commonNames['result']

// show all common names with a score of at least 100

?><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
	<meta name="language" content="en" />

	<!-- blueprint CSS framework -->
	<link rel="stylesheet" type="text/css" href="<?php echo $baseUrl; ?>css/screen.css" media="screen, projection" />
	<!--[if lt IE 8]>
	<link rel="stylesheet" type="text/css" href="<?php echo $baseUrl; ?>css/ie.css" media="screen, projection" />
	<![endif]-->

	<link rel="stylesheet" type="text/css" href="<?php $baseUrl; ?>css/main.css" />
	<link rel="stylesheet" type="text/css" href="<?php $baseUrl; ?>css/form.css" />
    <title>OpenUp! Common Names Webservice - ShowReferencesForScientificName CommonNames</title>
</head>

<body>

<div class="container" id="page">

	<div id="header">
		<div id="logo">OpenUp! Common Names Webservice</div>
	</div><!-- header -->

	<div id="mainmenu">&nbsp;</div><!-- mainmenu -->

    <div id='content'>
    <h3>Vernacular-Name references for '<?php echo htmlspecialchars($name); ?>'</h3>
    <?php
    foreach ($commonName_results as $commonName_result) {
        // only display 100% matches for reference
        if ($commonName_result['score'] >= 100) {
            ?>
            <p>
                <b><?php echo htmlspecialchars($commonName_result['name']); ?></b>
                <?php
                if (!empty($commonName_result['language'])) {
                    ?>
                    <i><?php echo htmlspecialchars($commonName_result['language']); ?></i>
                    <?php
                }
                ?>
                <ul>
                    <?php
                    foreach ($commonName_result['references'] as $commonName_reference) {
                        ?>
                        <li><?php echo htmlspecialchars($commonName_reference); ?></li>
                        <?php
                    }
                    ?>
                </ul>
            </p>
            <?php
        }
    }
    ?>
    </div>
</div><!-- page -->

</body>
</html>
