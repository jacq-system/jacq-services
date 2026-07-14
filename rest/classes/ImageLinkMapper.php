<?php
class ImageLinkMapper extends Mapper
{

    protected int $specimenID = 0;
    protected array $imageLinks = array();
    protected array $fileLinks = array();
    protected bool $linksActive = false;

public function __construct(mysqli $db, int $specimenID)
{
    parent::__construct($db);

    if ($specimenID == 0) {
        return;  // nothing to look for, so just stop
    }
    $this->specimenID = $specimenID;
}

public function getShowLink(int $nr = 0): mixed
{
    $this->linkbuilder();
    return $this->imageLinks[$nr] ?? $this->imageLinks[0] ?? '';
}

public function getDownloadLink(int $nr = 0): mixed
{
    $this->linkbuilder();
    return $this->fileLinks['full'][$nr] ?? $this->fileLinks['full'][0] ?? '';
}

public function getEuropeanaLink(int $nr = 0): mixed
{
    if ($nr < 1) { // only do this if it's the first (main) image
        $url = $this->db->query("SELECT url
                                 FROM gbif_pilot.europeana_images
                                 WHERE `specimen_ID` = $this->specimenID")
                        ->fetch_assoc();
        if (!empty($url['url'])) {  // use europeana-cache only for images without errors
            return $url['url'];
        }
    }
    $this->linkbuilder();
    return $this->fileLinks['europeana'][$nr] ?? $this->fileLinks['europeana'][0] ?? '';
}

public function getThumbLink(int $nr = 0): mixed
{
    $this->linkbuilder();
    return $this->fileLinks['thumb'][$nr] ?? $this->fileLinks['thumb'][0] ?? '';
}

public function getList(): array
{
    $this->linkbuilder();
    return array('show'     => $this->imageLinks,
                 'download' => $this->fileLinks);
}

// ---------------------------------------
// ---------- private functions ----------
// ---------------------------------------

/**
 * check if builder has run already and only build links if not
 *
 * @return void
 */
private function linkbuilder()
{
    if (!$this->linksActive) {
        $specimen = $this->db->query("SELECT s.digital_image, s.digital_image_obs, 
                                   id.`imgserver_type`, id.iiif_capable, 
                                   pc.specimenID as phaidra_sid
                                  FROM `tbl_specimens` s
                                   LEFT JOIN `tbl_management_collections` mc ON mc.`collectionID` = s.`collectionID`
                                   LEFT JOIN `tbl_img_definition` id         ON id.`source_id_fk` = mc.`source_id`
                                   LEFT JOIN `herbar_pictures`.`phaidra_cache` pc ON pc.specimenID = s.specimen_ID
                                  WHERE s.`specimen_ID` = $this->specimenID")
                             ->fetch_assoc();

        if (!empty($specimen['digital_image']) || !empty($specimen['digital_image_obs'])) {
            if ($specimen['phaidra_sid']) {
                // for now, special treatment for phaidra is needed when wu has images
                $this->phaidra();
            } elseif ($specimen['iiif_capable']) {
                $this->iiif();
            } elseif ($specimen['imgserver_type'] == 'bgbm') {
                $this->bgbm();
            } elseif ($specimen['imgserver_type'] == 'djatoka') {
                $this->djatoka();
            }
        }
        $this->linksActive = true;
    }
}
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
 * handle image server type phaidra
 *
 * @return void
 */
private function phaidra()
{
    $specimen = $this->db->query("SELECT s.`HerbNummer`, id.`HerbNummerNrDigits`, id.iiif_url
                                  FROM `tbl_specimens` s
                                   LEFT JOIN `tbl_management_collections` mc ON mc.`collectionID` = s.`collectionID`
                                   LEFT JOIN `tbl_img_definition` id         ON id.`source_id_fk` = mc.`source_id`
                                  WHERE s.`specimen_ID` = $this->specimenID")
                         ->fetch_assoc();

    $this->imageLinks[0] = $specimen['iiif_url'] . "?manifest=" . $this->getServiceBaseUrl() . "/iiif/manifest/$this->specimenID";
    $iiif = new IiifMapper($this->db);
    $manifest = $iiif->getManifest($this->specimenID);
    if ($manifest) {
        foreach ($manifest['sequences'] as $sequence) {
            foreach ($sequence['canvases'] as $canvas) {
                foreach ($canvas['images'] as $image) {
                    $this->fileLinks['full'][]      = $image['resource']['service']['@id'] . "/full/full/0/default.jpg";
                    $this->fileLinks['europeana'][] = $image['resource']['service']['@id'] . "/full/1200,/0/default.jpg";
                    $this->fileLinks['thumb'][]     = $image['resource']['service']['@id'] . "/full/160,/0/default.jpg";
                }
            }
        }
    }
}

/**
 * handle image server type iiif
 *
 * @return void
 */
private function iiif()
{
    $specimen = $this->db->query("SELECT id.iiif_url
                                  FROM `tbl_specimens` s
                                   LEFT JOIN `tbl_management_collections` mc ON mc.`collectionID` = s.`collectionID`
                                   LEFT JOIN `tbl_img_definition` id         ON id.`source_id_fk` = mc.`source_id`
                                  WHERE s.`specimen_ID` = $this->specimenID")
                         ->fetch_assoc();

    $iiif = new IiifMapper($this->db);
    $this->imageLinks[0] = $specimen['iiif_url'] . "?manifest=" . $iiif->getManifestUri($this->specimenID)['uri'];
    $manifest = $iiif->getManifest($this->specimenID);
    if ($manifest) {
        $version = 2;
        if (is_array($manifest['@context'])) {
            foreach ($manifest['@context'] as $context) {
                if ($context == "http://iiif.io/api/presentation/3/context.json") {
                    $version = 3;
                    break;
                }
            }
        }
        if ($version == 2) {
            foreach ($manifest['sequences'] as $sequence) {
                foreach ($sequence['canvases'] as $canvas) {
                    foreach ($canvas['images'] as $image) {
                        $this->fileLinks['full'][] = $image['resource']['service']['@id'] . "/full/max/0/default.jpg";
                        $this->fileLinks['europeana'][] = $image['resource']['service']['@id'] . "/full/1200,/0/default.jpg";
                        $this->fileLinks['thumb'][] = $image['resource']['service']['@id'] . "/full/160,/0/default.jpg";
                    }
                }
            }
        } else {
            foreach ($manifest['thumbnail'] as $thumbnail) {
                foreach ($thumbnail['service'] as $service) {
                    $this->fileLinks['full'][] = $service['id'] . "/full/max/0/default.jpg";
                    $this->fileLinks['europeana'][] = $service['id'] . "/full/1200,/0/default.jpg";
                    $this->fileLinks['thumb'][] = $service['id'] . "/full/160,/0/default.jpg";
                    break;  // use the first service only
                }
            }
        }
    }
}

/**
 * handle image server type bgbm
 *
 * @return void
 */
private function bgbm()
{
    $this->imageLinks[0] = 'https://www.jacq.org/image.php?filename=' . rawurlencode(basename($this->specimenID)) . "&sid=$this->specimenID&method=show";
    // there is no downloading of a picture
}

/**
 * handle image server type djatoka
 *
 * @return void
 */
private function djatoka()
{
    $specimen = $this->db->query("SELECT s.`HerbNummer`, 
                                   id.imgserver_url, id.`HerbNummerNrDigits`, id.`key`,
                                   mc.`coll_short_prj`, mc.`picture_filename`, mc.`source_id`,
                                   ei.url
                                  FROM `tbl_specimens` s
                                   LEFT JOIN `tbl_management_collections` mc ON mc.`collectionID` = s.`collectionID`
                                   LEFT JOIN `tbl_img_definition` id         ON id.`source_id_fk` = mc.`source_id`
                                   LEFT JOIN gbif_pilot.europeana_images ei  ON ei.specimen_ID = s.specimen_ID
                                  WHERE s.`specimen_ID` = $this->specimenID")
                         ->fetch_assoc();

    $HerbNummer = str_replace('-', '', $specimen['HerbNummer']);
    if (!empty($specimen['picture_filename'])) {   // special treatment for this collection is necessary
        $parts = $this->parser($specimen['picture_filename']);
        $filename = '';
        foreach ($parts as $part) {
            if ($part['token']) {
                $tokenParts = explode(':', $part['text']);
                $token = $tokenParts[0];
                switch ($token) {
                    case 'coll_short_prj':                                      // use contents of coll_short_prj
                        $filename .= $specimen['coll_short_prj'];
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
                            $filename .= sprintf("%0" . $specimen['HerbNummerNrDigits'] . ".0f", $number);
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
        $filename = sprintf("%s_%0" . $specimen['HerbNummerNrDigits'] . ".0f", $specimen['coll_short_prj'], $HerbNummer);
    }
    $images = array();
    try {
        // Create a client instance and send requests to jacq-servlet
        $client = new GuzzleHttp\Client(['timeout' => 8]);
        $response1 = $client->request('POST', $specimen['imgserver_url'] . 'jacq-servlet/ImageServer', [
            'json'   => ['method' => 'listResources',
                         'params' => [$specimen['key'],
                                       [ $filename,
                                         $filename . "_%",
                                         $filename . "A",
                                         $filename . "B",
                                         "tab_" . $this->specimenID,
                                         "obs_" . $this->specimenID,
                                         "tab_" . $this->specimenID . "_%",
                                         "obs_" . $this->specimenID . "_%"
                                       ]
                                     ],
                         'id'     => 1
                        ],
            'verify' => false
        ]);
        $data = json_decode($response1->getBody()->getContents(), true);
        if (!empty($data['error'])) {
            throw new Exception($data['error']);
        } elseif (empty($data['result'][0])) {
            if ($specimen['source_id'] == 47) { // FT returns always empty results...
                throw new Exception("FAIL: '$filename' returned empty result");
            }
        } else {
            foreach ($data['result'] as $pic) {
                $picProcessed = rawurlencode(basename($pic));
                if (substr($picProcessed, 0, 4) == 'obs_') {
                    $images_obs[] = $picProcessed;
                } elseif (substr($picProcessed, 0, 4) == 'tab_') {
                    $images_tab[] = $picProcessed;
                } else {
                    $images[] = "filename=$picProcessed&sid=" . $this->specimenID;
                }
            }
            if (!empty($images_obs)) {
                foreach($images_obs as $pic) {
                    $images[] = "filename=$pic&sid=" . $this->specimenID;
                }
            }
            if (!empty($images_tab)) {
                foreach($images_tab as $pic) {
                    $images[] = "filename=$pic&sid=" . $this->specimenID;
                }
            }
        }
    }
    catch( Exception $e ) {
        // something went wrong, so we fall back to the original filename
        $images[0] = 'filename=' . rawurlencode(basename($filename)) . '&sid=' . $this->specimenID;
    }

    if (!empty($images)) {
        $firstImage = true;
        foreach ($images as $image) {
            $this->imageLinks[] = 'https://www.jacq.org/image?' . $image . '&method=show';
            $this->fileLinks['full'][] = 'https://www.jacq.org/image?' . $image . '&method=download';
            if (!empty($specimen['url']) && $firstImage) {  // use europeana-cache only for images without errors and only for the first image
                $this->fileLinks['europeana'][] = $specimen['url'];
            } else {
                $this->fileLinks['europeana'][] = 'https://methus.jacq.org/image.php?' . $image . '&method=europeana';
            }
            $this->fileLinks['thumb'][] = 'https://www.jacq.org/image?' . $image . '&method=thumb';
            $firstImage = false;
        }
    }
}

}
