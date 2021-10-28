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
    $result = $this->db->query("SELECT taxonID FROM tbl_tax_species WHERE taxonID = $taxonID"); // check existence of taxon-ID before asking the internal service
    if ($result->num_rows > 0) {
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
    } else {
        $result = array('uuid' => '', 'url' => '');
    }

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
 * get scientific name without hybrids from database
 *
 * @param int $taxonID taxon-ID
 * @return string scientific name
 */
public function getTaxonName ($taxonID)
{
//    $this->db->query("CALL herbar_view._buildScientificNameComponents($taxonID, @scientificName, @author);");
//    $row = $this->db->query("SELECT @scientificName, @author")->fetch_assoc();
//    if ($row) {
//        $scientificName = $row['@scientificName'] . ' ' . $row['@author'];
//    } else {
//        $scientificName = '';
//    }
    $result = $this->db->query("SELECT `herbar_view`._buildScientificName($taxonID) AS sciname");
    if ($result) {
        $row = $result->fetch_assoc();
        $scientificName = trim($row['sciname']);
    } else {
        $scientificName = '';
    }

    return $scientificName;
}

/**
 * do a fulltext search in the scientific names (all parts of the term are mandatory, so a "+" is automatically inserted before each part)
 *
 * @param string $term search term
 * @return array results of search (taxonID and scientificName)
 */
public function searchScientificName ($term)
{
/*
INSERT INTO tbl_tax_sciname SELECT taxonID, herbar_view.GetScientificName(taxonID, 0), herbar_view._buildScientificName(taxonID) FROM tbl_tax_species
SELECT * FROM `tbl_tax_sciname` WHERE MATCH(scientificName) against('+prunus +avium' IN BOOLEAN MODE)
*/
    $parts = explode(" ", $term);
    $rows = $this->db->query("SELECT taxonID, scientificName, taxonName
                              FROM `tbl_tax_sciname`
                              WHERE MATCH(scientificName) against('" . $this->db->real_escape_string('+' . implode(" +", $parts)) . "' IN BOOLEAN MODE)")->fetch_all(MYSQLI_ASSOC);
    return $rows;
}

/**
 * get taxonID from database
 *
 * @param string $uuid uuid of taxon (uuid_minter_type_id = 1)
 * @return int taxonID (or null if none found)
 */
public function getTaxonID ($uuid)
{
    $curl = curl_init("https://resolve.jacq.org/resolve.php?uuid=$uuid&type=internal_id");
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    $curl_response = curl_exec($curl);
    if ($curl_response === false) {
//        $info = curl_getinfo($curl);
        curl_close($curl);
        return null;
    }
    curl_close($curl);
    return $curl_response;
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
//    return $decoded['internal_id'];
}

}
