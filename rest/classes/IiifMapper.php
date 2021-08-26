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
 * @return array received manifest
 */
public function getManifest($specimenID)
{
    $specimen = $this->db->query("SELECT s.specimen_ID, iiif.manifest_backend
                                  FROM tbl_specimens s
                                   LEFT JOIN tbl_management_collections mc        ON mc.collectionID = s.collectionID
                                   LEFT JOIN herbar_pictures.iiif_definition iiif ON iiif.source_id_fk = mc.source_id
                                  WHERE specimen_ID = '" . intval($specimenID) . "'")
                         ->fetch_assoc();
    $manifestBackend = $this->makeURI($specimen, $this->parser($specimen['manifest_backend']));

    $result = array();
    if ($manifestBackend) {
        $curl = curl_init($manifestBackend);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $curl_response = curl_exec($curl);
        if ($curl_response !== false) {
            $result = json_decode($curl_response, true);
        }
        curl_close($curl);
    }

    return $result;
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
                    if ($subtoken == 'stblid_manifest') {
                        $row_sid = $this->db->query("SELECT stableIdentifier
                                                     FROM tbl_specimens_stblid
                                                     WHERE specimen_ID = '" . $specimen['specimen_ID'] . "'
                                                     ORDER BY timestamp DESC
                                                     LIMIT 1")
                                            ->fetch_assoc();
                        $row = $this->db->query("SELECT manifest
                                                 FROM herbar_pictures.stblid_manifest
                                                 WHERE stableIdentifier LIKE '" . $row_sid['stableIdentifier'] . "'
                                                 LIMIT 1")
                                        ->fetch_assoc();
                        $uri .= $row['manifest'];
                    }
                    break;
            }
        } else {
            $uri .= $part['text'];
        }
    }

    return $uri;
}

}