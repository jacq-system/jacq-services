<?php
class JACQscinamesMapper extends Mapper
{
/**
 * all necessary settings
 * @var array
 */
private $settings;


public function __construct (mysqli $db, $settings)
{
    $this->settings = $settings;

    parent::__construct($db);
}

/**
 * use input-webservice "uuid" to get the uuid and uuid-url for a given taxon-id
 *
 * @param int $taxonID taxon-ID of uuid
 * @return array uuid and uuid-url returned from webservice
 */
public function getUuid ($taxonID)
{
    $curl = curl_init($this->settings['jacq_input_services'] . "tags/uuid/scientific_name/$taxonID");
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array('APIKEY: ' . $this->settings['apikey']));
    $curl_response = curl_exec($curl);
    if ($curl_response === false) {
        $result = array('uuid' => '', 'url' => '');
    } else {
        $result = json_decode($curl_response, true);
    }
    curl_close($curl);

    return $result;
}

/**
 * get scientific name from database
 *
 * @param int $taxonID taxon-ID
 * @return string scientific name
 */
public function getScientificName ($taxonID)
{
    $this->db->query("CALL herbar_view._buildScientificNameComponents($taxonID, @scientificName, @author);");
    $row = $this->db->query("SELECT @scientificName, @author")->fetch_assoc();
    if ($row) {
        $scientificName = $row['@scientificName'] . ' ' . $row['@author'];
    } else {
        $scientificName = '';
    }

    return $scientificName;
}

}