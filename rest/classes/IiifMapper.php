<?php
class IiifMapper extends Mapper
{

/**
 * get the URI of the iiif manifest of a given specimen-ID
 *
 * @param int $specimenID ID of specimen
 * @return string constructed uri or empty string if nothing found
 */
public function getManifestUri($specimenID)
{
    $specimen = $this->db->query("SELECT s.specimen_ID, iiif.manifest_uri
                                  FROM tbl_specimens s
                                   LEFT JOIN tbl_management_collections mc        ON mc.collectionID = s.collectionID
                                   LEFT JOIN herbar_pictures.iiif_definition iiif ON iiif.source_id_fk = mc.source_id
                                  WHERE specimen_ID = '" . intval($specimenID) . "'")
                         ->fetch_assoc();
    return array('uri' => $this->makeURI($specimen, $this->parser($specimen['manifest_uri'])));
}

/**
 * act as a proxy and get the iiif manifest of a given specimen-ID from the backend
 *
 * @param int $specimenID ID of specimen
 * @return array received manifest or false if no backend is defined
 */
public function getManifest($specimenID, $currentUri)
{
    $specimen = $this->db->query("SELECT s.specimen_ID, iiif.manifest_backend
                                  FROM tbl_specimens s
                                   LEFT JOIN tbl_management_collections mc        ON mc.collectionID = s.collectionID
                                   LEFT JOIN herbar_pictures.iiif_definition iiif ON iiif.source_id_fk = mc.source_id
                                  WHERE specimen_ID = '" . intval($specimenID) . "'")
                         ->fetch_assoc();
    if (!$specimen['manifest_backend']) {
        return false;
    } else {
        $manifestBackend = $this->makeURI($specimen, $this->parser($specimen['manifest_backend']));

        $result = array();
        if ($manifestBackend) {
            $curl = curl_init($manifestBackend);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            $curl_response = curl_exec($curl);
            if ($curl_response !== false) {
                $result = json_decode($curl_response, true);

                $result['@id'] = $currentUri;
                $addtlData = $this->getAddtlData($specimen['specimen_ID']);
                $result['description'] = $addtlData['description'];
                $result['metadata'] = $addtlData['metadata'];
            }
            curl_close($curl);
        }

        return $result;
    }
}


////////////////////////////// private functions //////////////////////////////
/**
 * parse text into parts and tokens (text within '<>')
 *
 * @param string $text text to tokenize
 * @return array found parts
 */
private function parser ($text)
{
    $parts = explode('<', $text);
    $result = array(array('text' => $parts[0], 'token' => false));
    for ($i = 1; $i < count($parts); $i++) {
        $subparts = explode('>', $parts[$i]);
        $result[] = array('text' => $subparts[0], 'token' => true);
        if (!empty($subparts[1])) {
            $result[] = array('text' => $subparts[1], 'token' => false);
        }
    }
    return $result;
}

/**
 * generate an uri out of several parts of a given specimen-ID. Understands tokens (specimenID, stableIdentifier, ...) and normal text
 *
 * @param int $specimenID ID of specimen
 * @param array $parts text and tokens
 * @return string constructed uri or empty string if nothing found
 */
private function makeURI ($specimen, $parts)
{
    $uri = '';
    foreach ($parts as $part) {
        if ($part['token']) {
            $tokenParts = explode(':', $part['text']);
            $token = $tokenParts[0];
            $subtoken = (isset($tokenParts[1])) ? $tokenParts[1] : '';
            switch ($token) {
                case 'specimenID':
                    $uri .= $specimen['specimen_ID'];
                    break;
                case 'stableIdentifier':
                    $row = $this->db->query("SELECT stableIdentifier
                                             FROM tbl_specimens_stblid
                                             WHERE specimen_ID = '" . $specimen['specimen_ID'] . "'
                                             ORDER BY timestamp DESC
                                             LIMIT 1")
                                    ->fetch_assoc();
                    switch ($subtoken) {
                        case 'last':
                            $uri .= substr($row['stableIdentifier'], strrpos($row['stableIdentifier'], '/') + 1);
                            break;
                        case 'https':
                            $uri .= str_replace('http:', 'https:', $row['stableIdentifier']);
                            break;
                        default:
                            $uri .= $row['stableIdentifier'];
                            break;
                    }
                    break;
                case 'fromDB':
                    // first subtoken must be the table name in db "herbar_pictures", second subtoken must be the column name to use for the result.
                    // where-clause is always the stable identifier and its column must be named "stableIdentifier".
                    if ($subtoken && !empty($tokenParts[2])) {
                        $row_sid = $this->db->query("SELECT stableIdentifier
                                                     FROM tbl_specimens_stblid
                                                     WHERE specimen_ID = '" . $specimen['specimen_ID'] . "'
                                                     ORDER BY timestamp DESC
                                                     LIMIT 1")
                                            ->fetch_assoc();
                        // SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(manifest, '/', -2), '/', 1) AS derivate_ID FROM `stblid_manifest` WHERE 1
                        $row = $this->db->query("SELECT " . $tokenParts[2] . "
                                                 FROM herbar_pictures.{$subtoken}
                                                 WHERE stableIdentifier LIKE '" . $row_sid['stableIdentifier'] . "'
                                                 LIMIT 1")
                                        ->fetch_assoc();
                        $uri .= $row[$tokenParts[2]];
                    }
                    break;
            }
        } else {
            $uri .= $part['text'];
        }
    }

    return $uri;
}

private function getAddtlData($specimenID)
{
    $row_sid = $this->db->query("SELECT stableIdentifier
                                 FROM tbl_specimens_stblid
                                 WHERE specimen_ID = $specimenID
                                 ORDER BY timestamp DESC
                                 LIMIT 1")
                        ->fetch_assoc();
    $row = $this->db->query("SELECT herbar_view.GetScientificName(s.taxonID, 0) AS sciName, tg.genus, te.epithet, s.observation
                             FROM tbl_specimens s
                              LEFT JOIN tbl_collector c ON s.SammlerID = c.SammlerID
                              LEFT JOIN tbl_tax_species ts ON s.taxonID = ts.taxonID
                              LEFT JOIN tbl_tax_rank ttr ON ts.tax_rankID = ttr.tax_rankID
                              LEFT JOIN tbl_management_collections mc ON s.collectionID = mc.collectionID
                              LEFT JOIN tbl_tax_epithets te  ON te.epithetID  = ts.speciesID
                              LEFT JOIN tbl_tax_genera tg ON tg.genID = ts.genID
                              LEFT JOIN tbl_tax_families tf ON tf.familyID = tg.familyID
                              LEFT JOIN tbl_geo_nation gn ON gn.nationID = s.NationID
                             WHERE s.specimen_ID = $specimenID")
                        ->fetch_assoc();

    $meta = array();
    $meta[] = array('label' => "dwc:materialSampleID",
                    'value' => $row_sid['stableIdentifier']);
    $meta[] = array('label' => "dwc:basisOfRecord",
                    'value' => (($row['observation'] > 0) ? "HumanObservation" : "PreservedSpecimen"));
    $meta[] = array('label' => "dwc:scientificName",
                    'value' => $row['sciName']);


    return array('description' => "A " . (($row['observation'] > 0) ? "HumanObservation" : "PreservedSpecimen") . " of " . $row['sciName'],
                 'metadata'    => $meta);
}

}