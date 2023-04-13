<?php
class IiifMapper extends Mapper
{

/**
 * get the URI of the iiif manifest of a given specimen-ID
 *
 * @param int $specimenID ID of specimen
 * @return array constructed uri or empty string if nothing found
 */
public function getManifestUri(int $specimenID): array
{
    $specimen = $this->db->query("SELECT s.specimen_ID, iiif.manifest_uri
                                  FROM tbl_specimens s
                                   LEFT JOIN tbl_management_collections mc        ON mc.collectionID = s.collectionID
                                   LEFT JOIN herbar_pictures.iiif_definition iiif ON iiif.source_id_fk = mc.source_id
                                  WHERE specimen_ID = '$specimenID'")
                         ->fetch_assoc();
    if (!empty($specimen['manifest_uri'])) {
        return array('uri' => $this->makeURI($specimen['specimen_ID'], $this->parser($specimen['manifest_uri'])));
    } else {
        return array('uri' => '');
    }
}

/**
 * act as a proxy and get the iiif manifest of a given specimen-ID from the backend
 *
 * @param int $specimenID ID of specimen
 * @param string $currentUri originally called uri
 * @return mixed received manifest or false if no backend is defined
 */
public function getManifest(int $specimenID, string $currentUri)
{
    $specimen = $this->db->query("SELECT s.specimen_ID,s.herbnummer, iiif.manifest_backend, iiif.manifest_uri, img.imgserver_Prot, img.imgserver_IP, img.iiif_dir, img.key
                                  FROM tbl_specimens s
                                  LEFT JOIN tbl_management_collections mc        ON mc.collectionID = s.collectionID
                                  LEFT JOIN herbar_pictures.iiif_definition iiif ON iiif.source_id_fk = mc.source_id
                                  LEFT JOIN herbarinput.tbl_img_definition img ON img.source_id_fk = mc.source_id
                                  WHERE specimen_ID = '$specimenID'")
                         ->fetch_assoc();
    if (!$specimen['manifest_backend']) {
        return false;
    } else {
        $manifestBackend = $this->makeURI($specimen['specimen_ID'], $this->parser($specimen['manifest_backend']));

        $result = array();
        if ($manifestBackend) {
            if(substr($manifestBackend,0,5) == 'POST:') {
                $url = substr($manifestBackend,5); //tbl_img_definition.imgserver_IP
                $urlmanifestpre = $this->makeURI($specimen['specimen_ID'], $this->parser($specimen['manifest_uri'])); //iiif_definition.manifest_uri
                $urlmanifestpost = '/manifest.json';
                //$urliiif = $specimen['imgserver_Prot'].'://'.$specimen['imgserver_IP'].$specimen['iiif_dir'].'/';
                $urliiif = $specimen['imgserver_Prot'].'://'.$specimen['imgserver_IP'].'/iiif/2/';
                $key = $specimen['key'];
                $filename = $this->getPictureData($specimenID); //woher? 'dr_045258'
                //$herbariumid = $this->makeURI($specimen['specimen_ID'], [['text'=>'stableIdentifier:last', 'token' => true]]);//'dr045258';
                $file_type = 'image/jpeg'; //eventuell aus Ergebnis request

                $data = array(
                    'id' => '1',
                    'method' => 'listResourcesWithMetadata',
                    'params' => array(
                        $key,
                        array(
                            $filename,
                            $filename . "_%",
                            $filename . "A",
                            $filename . "B",
                            "tab_" . $filename,
                            "obs_" . $filename,
                            "tab_" . $filename . "_%",
                            "obs_" . $filename . "_%"
                        )
                    )
                );

                $data_string = json_encode($data);
                $curl = curl_init();
                curl_setopt($curl, CURLOPT_URL, $url);
                curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($curl,CURLOPT_SSL_VERIFYHOST,0);
                curl_setopt($curl, CURLOPT_POST, true);
                curl_setopt($curl, CURLOPT_POSTFIELDS, $data_string);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curl, CURLOPT_HTTPHEADER, array(
                        'Content-Type: application/json',
                        'Content-Length: ' . strlen($data_string))
                );

                $curl_response = curl_exec($curl);

                $obj = json_decode($curl_response, TRUE);

                curl_close($curl);

                //$result = array();

                $context = array('http://iiif.io/api/presentation/2/context.json',
                    'http://www.w3.org/ns/anno.jsonld');
                $result['@context'] = $context ;
                $result['@id']      = $urlmanifestpre.$urlmanifestpost;
                $result['@type']      = 'sc:Manifest';
                $result['label']      = $specimenID;
                $canvases = array();
                for($i=0; $i<count($obj['result']); $i++) {
                    $canvases[] =  array('@id' => $urlmanifestpre.'/c/'.$specimenID.'_'.$i,
                        '@type' => 'sc:Canvas',
                        'label' =>  $obj['result'][$i]["identifier"],
                        'height' =>  $obj['result'][$i]["height"],
                        'width' =>  $obj['result'][$i]["width"],
                        'images' => array(array('@id' => $urlmanifestpre.'/i/'.$specimenID.'_'.$i,
                            '@type' => 'oa:Annotation',
                            'motivation' => 'sc:painting',
                            'on' => $urlmanifestpre.'/c/'.$specimenID.'_'.$i,
                            'resource' => array('@id' => $urliiif.str_replace('/','!',substr($obj['result'][$i]["path"],1)),
                                '@type' => 'dctypes:Image',
                                'format' => $file_type,
                                'height' => $obj['result'][$i]["height"],
                                'width' => $obj['result'][$i]["width"],
                                'service' => array('@context' => 'http://iiif.io/api/image/2/context.json',
                                    '@id' => $urliiif.str_replace('/','!',substr($obj['result'][$i]["path"],1)),
                                    'profile' => 'http://iiif.io/api/image/2/level2.json',
                                    'protocol' => 'http://iiif.io/api/image'
                                ),
                            ),
                        ),
                    ));

                };
                $sequences = array('@id' => $urlmanifestpre.'#sequence-1',
                    '@type' => 'sc:Sequence',
                    'canvases' => $canvases,
                    'label' => 'Current order',
                    'viewingDirection' => 'left-to-right'
                );
                $result['sequences']      = array($sequences);

                $result['thumbnail']      = array('@id' => $urliiif.str_replace('/','!',substr($obj['result'][0]["path"],1)).'/full/400,/0/default.jpg',
                    '@type' => 'dctypes:Image',
                    'format' => 'image/jpeg',
                    'service' => array('@context' => 'http://iiif.io/api/image/2/context.json',
                        '@id' => $urliiif.str_replace('/','!',substr($obj['result'][0]["path"],1)),
                        'profile' => 'http://iiif.io/api/image/2/level2.json',
                        'protocol' => 'http://iiif.io/api/image'
                    ),
                );

                    $specimen = new SpecimenMapper($this->db, $specimen['specimen_ID']);

                    $result['@id']         = $currentUri;  // to point at ourselves
                    $result['description'] = $specimen->getDescription();
                    $result['label']       = $specimen->getLabel();
                    $result['attribution'] = $specimen->getAttribution();
                    $result['logo']        = array('@id' => $specimen->getLogoURI());
                    $rdfLink               = array('@id'     => $specimen->getStableIdentifier(),
                        'label'   => 'RDF',
                        'format'  => 'application/rdf+xml',
                        'profile' => 'https://cetafidentifiers.biowikifarm.net/wiki/CSPP');
                    if (empty($result['seeAlso'])) {
                        $result['seeAlso']   = array($rdfLink);
                    } else {
                        $result['seeAlso'][] = $rdfLink;
                    }
                    $result['metadata']    = $this->getMetadataWithValues($specimen, (isset($result['metadata'])) ? $result['metadata'] : array());

            }else{
                $curl = curl_init($manifestBackend);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                $curl_response = curl_exec($curl);

            if ($curl_response !== false) {
                $result = json_decode($curl_response, true);
                $specimen = new SpecimenMapper($this->db, $specimen['specimen_ID']);

                $result['@id']         = $currentUri;  // to point at ourselves
                $result['description'] = $specimen->getDescription();
                $result['label']       = $specimen->getLabel();
                $result['attribution'] = $specimen->getAttribution();
                $result['logo']        = array('@id' => $specimen->getLogoURI());
                $rdfLink               = array('@id'     => $specimen->getStableIdentifier(),
                                               'label'   => 'RDF',
                                               'format'  => 'application/rdf+xml',
                                               'profile' => 'https://cetafidentifiers.biowikifarm.net/wiki/CSPP');
                if (empty($result['seeAlso'])) {
                    $result['seeAlso']   = array($rdfLink);
                } else {
                    $result['seeAlso'][] = $rdfLink;
                }
                $result['metadata']    = $this->getMetadataWithValues($specimen, (isset($result['metadata'])) ? $result['metadata'] : array());
            }
            curl_close($curl);
            };
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
private function parser (string $text): array
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
private function makeURI (int $specimenID, array $parts): string
{
    $uri = '';
    foreach ($parts as $part) {
        if ($part['token']) {
            $tokenParts = explode(':', $part['text']);
            $token = $tokenParts[0];
            $subtoken = (isset($tokenParts[1])) ? $tokenParts[1] : '';
            switch ($token) {
                case 'specimenID':
                    $uri .= $specimenID;
                    break;
                case 'stableIdentifier':
                    $row = $this->db->query("SELECT stableIdentifier
                                             FROM tbl_specimens_stblid
                                             WHERE specimen_ID = '$specimenID'
                                             ORDER BY timestamp DESC
                                             LIMIT 1")
                                    ->fetch_assoc();
                    if (!empty($row)) {
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
                    }
                    break;
                case 'fromDB':
                    // first subtoken must be the table name in db "herbar_pictures", second subtoken must be the column name to use for the result.
                    // where-clause is always the stable identifier and its column must be named "stableIdentifier".
                    if ($subtoken && !empty($tokenParts[2])) {
                        $row_sid = $this->db->query("SELECT stableIdentifier
                                                     FROM tbl_specimens_stblid
                                                     WHERE specimen_ID = '$specimenID'
                                                     ORDER BY timestamp DESC
                                                     LIMIT 1")
                                            ->fetch_assoc();
                        // SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(manifest, '/', -2), '/', 1) AS derivate_ID FROM `stblid_manifest` WHERE 1
                        $row = $this->db->query("SELECT " . $tokenParts[2] . "
                                                 FROM herbar_pictures.$subtoken
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

/**
 * get array of metadata for a given specimen
 *
 * @param SpecimenMapper $specimen specimen to get metadata from
 * @param array $metadata already existing metadata in manifest (optional)
 * @return array metadata
 */
private function getMetadata(SpecimenMapper $specimen, array $metadata = array()): array
{
    $meta = $metadata;

    $dcData = $specimen->getDC();
    foreach ($dcData as $label => $value) {
        $meta[] = array('label' => $label,
                        'value' => $value);
    }

    $dwcData = $specimen->getDWC();
    foreach ($dwcData as $label => $value) {
        $meta[] = array('label' => $label,
                        'value' => $value);
    }

    $specimenProperties = $specimen->getProperties();

    $meta[] = array('label' => 'CETAF_ID',          'value' => $specimenProperties['stableIdentifier']);
    $meta[] = array('label' => 'dwciri:recordedBy', 'value' => $specimenProperties['WIKIDATA_ID']);
    if (!empty($specimenProperties['HUH_ID'])) {
        $meta[] = array('label' => 'owl:sameAs', 'value' => $specimenProperties['HUH_ID']);
    }
    if (!empty($specimenProperties['VIAF_ID'])) {
        $meta[] = array('label' => 'owl:sameAs', 'value' => $specimenProperties['VIAF_ID']);
    }
    if (!empty($specimenProperties['ORCID'])) {
        $meta[] = array('label' => 'owl:sameAs', 'value' => $specimenProperties['ORCID']);
    }
    if (!empty($specimenProperties['WIKIDATA_ID'])) {
        $meta[] = array('label' => 'owl:sameAs', 'value' => $specimenProperties['WIKIDATA_ID']);
        $meta[] = array('label' => 'owl:sameAs', 'value' => "https://scholia.toolforge.org/author/" . basename($specimenProperties['WIKIDATA_ID']));
    }

    foreach ($meta as $key => $line) {
        if (substr($line['value'], 0, 7) === 'http://' || substr($line['value'], 0, 8) === 'https://') {
            $meta[$key]['value'] = "<a href='" . $line['value'] . "'>" . $line['value'] . "</a>";
        }
    }

    return $meta;
}

/**
 * get array of metadata for a given specimen, where values are not empty
 *
 * @param SpecimenMapper $specimen specimen to get metadata from
 * @param array $metadata already existing metadata in manifest (optional)
 * @return array metadata
 */
private function getMetadataWithValues(SpecimenMapper $specimen, array $metadata = array()): array
{
    $meta = $this->getMetadata($specimen, $metadata);
    $result = array();
    foreach ($meta as $row) {
        if (!empty($row['value'])) {
            $result[] = $row;
        }
    }
    return $result;
}
private function getPictureData($specimenID)
    {
        $result = $this->db->query("SELECT id.`imgserver_Prot`, id.`imgserver_IP`, id.`imgserver_type`, id.`img_service_directory`, id.`is_djatoka`, id.`HerbNummerNrDigits`, id.`key`,
                   mc.`coll_short_prj`, mc.`source_id`, mc.`collectionID`, mc.`picture_filename`,
                   s.`HerbNummer`, s.`Bemerkungen`
            FROM `tbl_specimens` s
             LEFT JOIN `tbl_management_collections` mc ON mc.`collectionID` = s.`collectionID`
             LEFT JOIN `tbl_img_definition` id ON id.`source_id_fk` = mc.`source_id`
            WHERE s.`specimen_ID` = '" . intval($specimenID) . "'")
             ->fetch_assoc();
        // Fetch information for this image
        if ($result) {
           $url = ((!empty($result['imgserver_Prot'])) ? $result['imgserver_Prot'] : "http") . '://'
                . $result['imgserver_IP']
                . (($result['img_service_directory']) ? '/' . $result['img_service_directory'] . '/' : '/');

            // Remove hyphens
            $HerbNummer = str_replace('-', '', $result['HerbNummer']);


            // Construct clean filename
            if ($result['imgserver_type'] == 'bgbm') {
                // Remove spaces for B HerbNumber
                $HerbNummer = ($result['HerbNummer']) ? $result['HerbNummer'] : ('JACQID' . $specimenID);
                $HerbNummer = str_replace(' ', '', $HerbNummer);
                $filename = sprintf($HerbNummer);
                $key = $result['key'];
            } elseif ($result['imgserver_type'] == 'baku') {       // depricated
                $html = $result['Bemerkungen'];
// create new ImageQuery object
                $query = new ImageQuery();

// fetch image uris
                try {
                    $uris = $query->fetchUris($html);
                } catch (Exception $e) {
                    echo 'an error occurred: ', $e->getMessage(), "\n";
                    die();
                }

// do something with uris
                foreach ($uris as $uriSubset) {
                    $newHtmlCode = '<a href="' . $uriSubset["image"] . '" target="_blank"><img src="' . $uriSubset["preview"] . '"/></a>';
                }

                $url = $uriSubset["base"];
#$url .= ($row['img_service_directory']) ? '/' . $row['img_service_directory'] . '/' : '';
                if (substr($url, -1) != '/') {
                    $url .= '/';  // to ensure that $url ends with a slash
                }
                $filename = sprintf($uriSubset["filename"]);
                $originalFilename = sprintf($uriSubset["thumb"]);
                $key = sprintf($uriSubset["html"]);
            } else {
                if ($result['collectionID'] == 90 || $result['collectionID'] == 92 || $result['collectionID'] == 123) { // w-krypt needs special treatment
                    /* TODO
                     * specimens of w-krypt are currently under transition from the old numbering system (w-krypt_1990-1234567) to the new
                     * numbering system (w_1234567). During this time, new HerbNumbers are given to the specimens and the entries
                     * in tbl_specimens are changed accordingly.
                     * So, this script should first look for pictures, named after the new system before searching for pictures, named after the old system
                     * When the transition is finished, this code-part (the whole elseif-block) should be removed
                     * Johannes Schachner, 25.9.2021
                     */
                    $filename = sprintf("w_%0" . $result['HerbNummerNrDigits'] . ".0f", $HerbNummer);
                    try {  // ask the picture server for a picture with the new filename
                        $service = new \JsonRPC\Client($url . 'jacq-servlet/ImageServer');
                        $pics = $service->execute('listResources',
                            [
                                $result['key'],
                                [
                                    $filename,
                                    $filename . "_%",
                                    $filename . "A",
                                    $filename . "B",
                                    "tab_" . $filename,
                                    "obs_" . $filename,
                                    "tab_" . $filename . "_%",
                                    "obs_" . $filename . "_%"
                                ]
                            ]);
                    } catch (Exception $e) {
                        $pics = array();  // something has gone wrong, so no picture can be found anyway
                    }
                    if (count($pics) == 0) {  // nothing found, so use the old filename
                        $filename = sprintf("w-krypt_%0" . $result['HerbNummerNrDigits'] . ".0f", $HerbNummer);
                    }
                } elseif (!empty($result['picture_filename'])) {   // special treatment for this collection is necessary
                    $parts = $this-> parser($result['picture_filename']);
                    $filename = '';
                    foreach ($parts as $part) {
                        if ($part['token']) {
                            $tokenParts = explode(':', $part['text']);
                            $token = $tokenParts[0];
                            switch ($token) {
                                case 'coll_short_prj':                                      // use contents of coll_short_prj
                                    $filename .= $result['coll_short_prj'];
                                    break;
                                case 'HerbNummer':                                          // use HerbNummer with removed hyphens, options are :num and :reformat
                                    if (in_array('num', $tokenParts)) {                     // ignore text with digits within, only use the last number
                                        if (preg_match("/\d+$/", $HerbNummer, $matches)) {  // there is a number at the tail of HerbNummer
                                            $number = $matches[0];
                                        } else {                                            // HerbNummer ends with text
                                            $number = 0;
                                        }
                                    } else {
                                        $number = $HerbNummer;                              // use the complete HerbNummer
                                    }
                                    if (in_array("reformat", $tokenParts)) {                // correct the number of digits with leading zeros
                                        $filename .= sprintf("%0" . $result['HerbNummerNrDigits'] . ".0f", $number);
                                    } else {                                                // use it as it is
                                        $filename .= $number;
                                    }
                                    break;
                            }
                        } else {
                            $filename .= $part['text'];
                        }
                    }
                } else {    // standard filename, would be "<coll_short_prj>_<HerbNummer:reformat>"
                    $filename = sprintf("%s_%0" . $result['HerbNummerNrDigits'] . ".0f", $result['coll_short_prj'], $HerbNummer);
                }
                $key = $result['key'];
            }

// Set original file-name if we didn't pass one (required for djatoka)
// (required for pictures with suffixes)
            //if (!$originalFilename) {
             //   $originalFilename = $filename;
            //}

            //return array(
            //    'url' => $url,
            //    'requestFileName' => $filename,
            //    //'originalFilename' => $originalFilename,
            //    'filename' => $filename,
            //   'specimenID' => $specimenID,
            //    'is_djatoka' => $result['is_djatoka'],
            //    'imgserver_type' => $result['imgserver_type'],
            //    'key' => $key
            //);
            return $filename;
        } else {
            return array(
                'url' => null,
                'requestFileName' => null,
                //'originalFilename' => null,
                'filename' => null,
                'specimenID' => null,
                'is_djatoka' => null,
                'imgserver_type' => null,
                'key' => null
            );
        }
    }

}
