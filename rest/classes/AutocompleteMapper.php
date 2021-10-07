<?php
class AutocompleteMapper extends Mapper
{

/**
 * Search for fitting scientific names and return them
 */
public function getScientificNames($term)
{
    $pieces = explode(' ', $term);

    // Check for valid input
    if (count($pieces) <= 0 || empty($pieces[0])) {
        return array();
    }

    $sql_1 = "SELECT ts.taxonID, herbar_view.GetScientificName(ts.taxonID, 0) AS ScientificName
              FROM tbl_tax_species ts
               LEFT JOIN tbl_tax_genera tg ON tg.genID = ts.genID ";
    $sql_2 = "WHERE ts.external = 0
               AND tg.genus LIKE '" . $this->db->escape_string($pieces[0]) . "%' ";
    // Check if we search the first epithet as well
    if (count($pieces) >= 2 && !empty($pieces[1])) {
        $sql_1 .= " LEFT JOIN tbl_tax_epithets te0 ON te0.epithetID = ts.speciesID ";
        $sql_2 .= " AND te0.epithet LIKE '" . $this->db->escape_string($pieces[1]) . "%' ";
    } else {
        $sql_2 .= " AND ts.speciesID IS NULL ";
    }
    $rows = $this->db->query($sql_1 . $sql_2 . " ORDER BY ScientificName")->fetch_all(MYSQLI_ASSOC);

    $results = array();
    foreach ($rows as $row) {
        $taxonID = $row['taxonID'];
        $scientificName = $row['ScientificName'];

        //$scientificName = $this->getTaxonName($taxonID);

        if (!empty($scientificName)) {
            $results[] = array(
                "label" => $scientificName,
                "value" => $scientificName,
                "id"    => $taxonID,
                "uuid"  => array('href' => $this->getParentUrl() . 'JACQscinames/uuid/' . $taxonID),
            );
        }
    }

    return $results;
}



////////////////////////////// private functions //////////////////////////////

/**
 * get the url of the parent directory to call another jacq service
 * @return string url to parent directory
 */
private function getParentUrl()
{
    $path = dirname(filter_input(INPUT_SERVER, 'SCRIPT_NAME', FILTER_SANITIZE_STRING));
    $pos  = strrpos($path, '/');

    return filter_input(INPUT_SERVER, 'REQUEST_SCHEME', FILTER_SANITIZE_STRING) . "://"
         . filter_input(INPUT_SERVER, 'HTTP_HOST', FILTER_SANITIZE_STRING)
         . substr($path, 0, $pos) . '/';
}

}