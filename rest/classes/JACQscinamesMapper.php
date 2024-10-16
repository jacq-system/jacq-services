<?php
class JACQscinamesMapper extends Mapper
{
/**
 * all necessary settings
 * @var array
 */
private $settings;
private $uuidUrl = '';


public function __construct(mysqli $db, $settings)
{
    $this->settings = $settings;

    parent::__construct($db);
}

/**
 * use the input-webservice "uuid" to get the uuid and uuid-url for a given taxon-ID. Return the uuid and store the url for later retrieval
 *
 * @param int $taxonID taxon-ID of uuid
 * @return string uuid returned from webservice
 */
public function getUuid($taxonID): string
{
    $uuidData = array('uuid' => '',
                      'url'  => '');

    $resCheck = $this->db->query("SELECT taxonID FROM tbl_tax_species WHERE taxonID = $taxonID"); // check existence of taxon-ID before asking the internal service
    if ($resCheck->num_rows > 0) {
        $curl = curl_init($this->settings['jacq_input_services'] . "tags/uuid/scientific_name/$taxonID");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('APIKEY: ' . $this->settings['apikey']));
        $curl_response = curl_exec($curl);
        if ($curl_response !== false) {
            $uuidData = json_decode($curl_response, true);
            $this->uuidUrl = $uuidData['url'];
        }
        curl_close($curl);
    }

    return $uuidData['uuid'];
}

/**
 * return the uuid-url (if there is any)
 *
 * @return string url stored earlier
 */
public function getUuidUrl(): string
{
    return $this->uuidUrl;
}

/**
 * get scientific name from database for a given taxon-ID
 *
 * @param int $taxonID taxon-ID
 * @return string scientific name
 */
public function getScientificName($taxonID): string
{
//    $this->db->query("CALL herbar_view._buildScientificNameComponents($taxonID, @scientificName, @author);");
//    $row = $this->db->query("SELECT @scientificName, @author")->fetch_assoc();
//    if ($row) {
//        $scientificName = $row['@scientificName'] . ' ' . $row['@author'];
//    } else {
//        $scientificName = '';
//    }
    $result = $this->db->query("SELECT `herbar_view`.GetScientificName($taxonID, 0) AS sciname");
    if ($result) {
        $row = $result->fetch_assoc();
        $scientificName = trim($row['sciname']);
    } else {
        $scientificName = '';
    }

    return $scientificName;
}

/**
 * get scientific name without hybrids from database for a given taxon-ID
 *
 * @param int $taxonID taxon-ID
 * @return string scientific name
 */
public function getTaxonName($taxonID): string
{
    $result = $this->db->query("SELECT `herbar_view`.GetTaxonName($taxonID) AS taxname");
    if ($result) {
        $row = $result->fetch_assoc();
        $taxonName = trim($row['taxname']);
    } else {
        $taxonName = '';
    }

    return $taxonName;
}

/**
 * do a fulltext search in the scientific names (all parts of the term are mandatory, so a "+" is automatically inserted before each part)
 *
 * @param string $term search term
 * @return array results of search (taxonID and scientificName)
 */
public function findScientificName($term): array
{
/*
INSERT INTO tbl_tax_sciname SELECT taxonID, herbar_view.GetScientificName(taxonID, 0), herbar_view._buildScientificName(taxonID) FROM tbl_tax_species
SELECT * FROM `tbl_tax_sciname` WHERE MATCH(scientificName) against('+prunus +avium' IN BOOLEAN MODE)
*/
    $parts = explode(" ", $term);
    $rows = $this->db->query("SELECT taxonID, scientificName, taxonName
                              FROM `tbl_tax_sciname`
                              WHERE MATCH(scientificName) against('" . $this->db->real_escape_string('+' . implode(" +", $parts)) . "' IN BOOLEAN MODE)
                               OR MATCH(taxonName) against('" . $this->db->real_escape_string('+' . implode(" +", $parts)) . "' IN BOOLEAN MODE)
                              ORDER BY scientificName")
                     ->fetch_all(MYSQLI_ASSOC);
    return $rows;
}

/**
 * use resolver to get the taxon-ID and url for a given uuid. Return the taxon-ID and store the url for later retrieval
 *
 * @param string $uuid uuid of taxon (uuid_minter_type_id = 1)
 * @return int taxonID (or null if none found)
 */
public function getTaxonID($uuid)
{
    $curl = curl_init("https://resolve.jacq.org/resolve.php?uuid=$uuid&type=internal_id");
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    $curl_response = curl_exec($curl);
    if ($curl_response === false) {
//        $info = curl_getinfo($curl);
        curl_close($curl);
        return null;
    } else {
        $taxonID = intval($curl_response);
        $this->uuidUrl = "https://resolve.jacq.org/$uuid";
    }
    curl_close($curl);

    return $taxonID;
//    $curl = curl_init($this->settings['jacq_input_services'] . "tags/ids/$uuid");
//    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
//    curl_setopt($curl, CURLOPT_HTTPHEADER, array('APIKEY: ' . $this->settings['apikey']));
//    $curl_response = curl_exec($curl);
//    if ($curl_response === false) {
////        $info = curl_getinfo($curl);
//        curl_close($curl);
//        return null;
//    }
//    curl_close($curl);
//    $decoded = json_decode($curl_response, true);
//    return array('taxonID' => intval($decoded['internal_id']), 'uuid' => $decoded['uuid'], 'url' => $decoded['url']);
}

}
