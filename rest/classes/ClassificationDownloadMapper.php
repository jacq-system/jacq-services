<?php
class ClassificationDownloadMapper extends Mapper
{
/**
* hide scientific name authors in output file
* @var boolean
*/
private $hideScientificNameAuthors = false;

/**
 * header-line, pre-filled with fixed titles
 * @var array
 */
private $outputHeader = array("reference_guid",       "reference",          "license",                   "downloaded",                  "modified",
                              "scientific_name_guid", "scientific_name_id", "parent_scientific_name_id", "accepted_scientific_name_id", "taxonomic_status");

/**
 * fill with amount of prefixed headers
 * @var int
 */
private $outputHeaderPrefixLen;

/**
 * body lines
 * @var array
 */
private $outputBody = array();

private $settings;


public function __construct (mysqli $db, $settings)
{
    $this->settings = $settings;

    parent::__construct($db);
}

public function createDownload($referenceType, $referenceId, $scientificNameId = 0, $hideScientificNameAuthors = null)
{
    if (empty($referenceType) || empty($referenceId)) {
        return array();
    }

    switch ($hideScientificNameAuthors) {
        case "true":
            $this->hideScientificNameAuthors = 1;
            break;
        case "false":
            $this->hideScientificNameAuthors = 0;
            break;
        default:
            // if hide scientific name authors is null, use preference from literature entry
            $this->hideScientificNameAuthors = $this->getHideScientificNameAuthors($referenceId);
            break;
    }

    // check if a certain scientific name id is specified & load the fitting synonymy entry
    $sql = "SELECT tsy.source_citationID, tsy.taxonID, tsy.acc_taxon_ID,
                   tr.rank_hierarchy,
                   tc.parent_taxonID,
                   `herbar_view`.GetProtolog(l.citationID) AS citation
            FROM tbl_tax_synonymy tsy
             LEFT JOIN tbl_tax_species ts ON ts.taxonID = tsy.taxonID
             LEFT JOIN tbl_tax_rank tr ON tr.tax_rankID = ts.tax_rankID
             LEFT JOIN tbl_lit l ON l.citationID = tsy.source_citationID
             LEFT JOIN tbl_tax_classification tc ON tc.tax_syn_ID = tsy.tax_syn_ID ";
    if ($scientificNameId > 0) {
        $dbRowsTaxSynonymy[] = $this->db->query($sql . " WHERE tsy.source_citationID = $referenceId
                                                          AND tsy.acc_taxon_ID IS NULL
                                                          AND tsy.taxonID = $scientificNameId")
                                        ->fetch_assoc();
    }
    // if not, fetch all top-level entries for this reference
    else {
        $dbRowsTaxSynonymy = $this->db->query($sql . " WHERE tsy.source_citationID = $referenceId
                                                        AND tsy.acc_taxon_ID IS NULL
                                                        AND tc.classification_id IS NULL")
                                      ->fetch_all(MYSQLI_ASSOC);
    }

    $this->outputHeaderPrefixLen = count($this->outputHeader);

    $tax_ranks = $this->getRankHierarchies();
    foreach ($tax_ranks as $rank) {
        $this->outputHeader[] = $rank['rank'];
    }

    foreach ($dbRowsTaxSynonymy as $dbRowTaxSynonymy) {
        $this->exportClassification(array(), $dbRowTaxSynonymy);
    }

    return array('header' => $this->outputHeader, 'body' => $this->outputBody);
}



////////////////////////////// private functions //////////////////////////////

private function exportClassification ($parentTaxSynonymies, $taxSynonymy)
{

    $line[0] = $this->getUuidUrl('citation', $taxSynonymy['source_citationID']);
    $line[1] = $taxSynonymy['citation'];
    $line[2] = $this->settings['classifications_license'];
    $line[3] = date("Y-m-d H:i:s");
    $line[4] = '';
    $line[5] = $this->getUuidUrl('scientific_name', $taxSynonymy['taxonID']);
    $line[6] = $taxSynonymy['taxonID'];
    $line[7] = $taxSynonymy['parent_taxonID'];
    $line[8] = $taxSynonymy['acc_taxon_ID'];
    $line[9] = ($taxSynonymy['acc_taxon_ID']) ? 'synonym' : 'accepted';

    // add parent information
    foreach ($parentTaxSynonymies as $parentTaxSynonymy) {
        $line[$this->outputHeaderPrefixLen + $parentTaxSynonymy['rank_hierarchy'] - 1] = $this->getScientificName($parentTaxSynonymy['taxonID']);
    }

    // add the currently active information
    $line[$this->outputHeaderPrefixLen + $taxSynonymy['rank_hierarchy'] - 1] = $this->getScientificName($taxSynonymy['taxonID']);

    $this->outputBody[] = $line;

    // fetch all synonyms
    $taxSynonymySynonyms = $this->db->query("SELECT tsy.source_citationID, tsy.taxonID, tsy.acc_taxon_ID,
                                                    tr.rank_hierarchy,
                                                    tc.parent_taxonID,
                                                    `herbar_view`.GetProtolog(l.citationID) AS citation,
                                                    tc.classification_id
                                             FROM tbl_tax_synonymy tsy
                                              LEFT JOIN tbl_tax_species ts ON ts.taxonID = tsy.taxonID
                                              LEFT JOIN tbl_tax_rank tr ON tr.tax_rankID = ts.tax_rankID
                                              LEFT JOIN tbl_lit l ON l.citationID = tsy.source_citationID
                                              LEFT JOIN tbl_tax_classification tc ON tc.tax_syn_ID = tsy.tax_syn_ID
                                             WHERE tsy.source_citationID = " . $taxSynonymy['source_citationID'] . "
                                              AND tsy.acc_taxon_ID = " . $taxSynonymy['taxonID'])
                                    ->fetch_all(MYSQLI_ASSOC);
    foreach ($taxSynonymySynonyms as $taxSynonymySynonym) {
        $this->exportClassification($parentTaxSynonymies, $taxSynonymySynonym);
    }

    // fetch all children
    $parentTaxSynonymies[] = $taxSynonymy;
    $taxSynonymyChildren = $this->db->query("SELECT tsy.source_citationID, tsy.taxonID, tsy.acc_taxon_ID,
                                                    tr.rank_hierarchy,
                                                    tc.parent_taxonID,
                                                    `herbar_view`.GetProtolog(l.citationID) AS citation,
                                                    tc.classification_id
                                             FROM tbl_tax_synonymy tsy
                                              LEFT JOIN tbl_tax_species ts ON ts.taxonID = tsy.taxonID
                                              LEFT JOIN tbl_tax_rank tr ON tr.tax_rankID = ts.tax_rankID
                                              LEFT JOIN tbl_lit l ON l.citationID = tsy.source_citationID
                                              LEFT JOIN tbl_tax_classification tc ON tc.tax_syn_ID = tsy.tax_syn_ID
                                             WHERE tsy.source_citationID = " . $taxSynonymy['source_citationID'] . "
                                              AND tc.parent_taxonID = " . $taxSynonymy['taxonID'] . "
                                             ORDER BY tc.order ASC")
                                    ->fetch_all(MYSQLI_ASSOC);
    foreach ($taxSynonymyChildren as $taxSynonymyChild) {
        $this->exportClassification($parentTaxSynonymies, $taxSynonymyChild);
    }

}

/**
 * get all hierarchy names and numbers
 *
 * @return array list of all tax_rank hierarchies ['rank'] and numbers ['hierarchy']
 */
private function getRankHierarchies()
{
    return $this->db->query("SELECT rank, rank_hierarchy AS hierarchy
                             FROM tbl_tax_rank
                             ORDER BY rank_hierarchy ASC")
                    ->fetch_all(MYSQLI_ASSOC);
}

/**
 * what tells tbl_lit about hiding scientific name authors
 *
 * @param int $referenceId citationID
 * @return int hide (1) or show (0) author name
 */
private function getHideScientificNameAuthors ($referenceId)
{
    return $this->db->query("SELECT hideScientificNameAuthors
                             FROM tbl_lit
                             WHERE citationID = $referenceId")
                    ->fetch_assoc()['hideScientificNameAuthors'];
}

/**
 * use input-webservice "uuid" to get the uuid-url for a given id and type
 *
 * @param mixed $type type of uuid (1 or scientific_name, 2 or citation 3 or specimen)
 * @param int $id internal-id of uuid
 * @return string uuid-url returned from webservice
 */
private function getUuidUrl ($type, $id)
{
    $curl = curl_init($this->settings['jacq_input_services'] . "uuid/$type/$id");
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array('APIKEY: ' . $this->settings['apikey']));
    $curl_response = curl_exec($curl);
    if ($curl_response === false) {
        $result = '';
    } else {
        $json = json_decode($curl_response, true);
        $result = $json['url'];
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
private function getScientificName ($taxonID)
{
    $this->db->query("CALL herbar_view._buildScientificNameComponents($taxonID, @scientificName, @author);");
    $row = $this->db->query("SELECT @scientificName, @author")->fetch_assoc();
    if ($row) {
        $scientificName = $row['@scientificName'];
        if (!$this->hideScientificNameAuthors) {
            $scientificName .= ' ' . $row['@author'];
        }
    } else {
        $scientificName = '';
    }

    return $scientificName;
}
}
