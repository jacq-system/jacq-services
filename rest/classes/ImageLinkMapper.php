<?php
class ImageLinkMapper extends Mapper
{

    protected int $specimenID = 0;
    protected array $imageLinks = array();
    protected array $fileLinks = array();

public function __construct(mysqli $db, int $specimenID)
{
    parent::__construct($db);

    if ($specimenID == 0) {
        return;  // nothing to look for, so just stop
    }
    $this->specimenID = $specimenID;

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
}

public function getFirstImageShowLink()
{
    return $this->imageLinks[0] ?? '';
}

public function getFirstImageDownloadLink()
{
    return $this->fileLinks[0] ?? '';
}

// ---------------------------------------
// ---------- private functions ----------
// ---------------------------------------

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
    $specimen = $this->db->query("SELECT s.`HerbNummer`, id.`HerbNummerNrDigits`, id.iiif_proxy, id.iiif_dir
                                  FROM `tbl_specimens` s
                                   LEFT JOIN `tbl_management_collections` mc ON mc.`collectionID` = s.`collectionID`
                                   LEFT JOIN `tbl_img_definition` id         ON id.`source_id_fk` = mc.`source_id`
                                  WHERE s.`specimen_ID` = $this->specimenID")
                         ->fetch_assoc();

    $this->imageLinks[0] = 'https://' . $specimen['iiif_proxy'] . $specimen['iiif_dir'] . '/'
                         . "?manifest=$this->serviceBaseUri/iiif/manifest/$this->specimenID";
    $iiif = new IiifMapper($this->db);
    $manifest = $iiif->getImageManifest($this->specimenID);
    if ($manifest) {
        foreach ($manifest['sequences'] as $sequence) {
            foreach ($sequence['canvases'] as $canvas) {
                foreach ($canvas['images'] as $image) {
                    $this->fileLinks[] = 'https://www.jacq.org/downloadPhaidra.php?filename='
                                       . sprintf("WU%0" . $specimen['HerbNummerNrDigits'] . ".0f", str_replace('-', '', $specimen['HerbNummer']))
                                       . ".jpg&url=" . $image['resource']['service']['@id'] . "/full/full/0/default.jpg";
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
    $specimen = $this->db->query("SELECT id.iiif_proxy, id.iiif_dir
                                  FROM `tbl_specimens` s
                                   LEFT JOIN `tbl_management_collections` mc ON mc.`collectionID` = s.`collectionID`
                                   LEFT JOIN `tbl_img_definition` id         ON id.`source_id_fk` = mc.`source_id`
                                  WHERE s.`specimen_ID` = $this->specimenID")
                         ->fetch_assoc();

    $iiif = new IiifMapper($this->db);
    $this->imageLinks[0] = 'https://' . $specimen['iiif_proxy'] . $specimen['iiif_dir'] . '/' . "?manifest=" . $iiif->getManifestUri($this->specimenID)['uri'];
    // TODO: implement a link for download
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
                                   id.`imgserver_Prot`, id.`imgserver_IP`, id.`img_service_directory`,  id.`HerbNummerNrDigits`, id.`key`,
                                   mc.`coll_short_prj`, mc.`picture_filename`
                                  FROM `tbl_specimens` s
                                   LEFT JOIN `tbl_management_collections` mc ON mc.`collectionID` = s.`collectionID`
                                   LEFT JOIN `tbl_img_definition` id         ON id.`source_id_fk` = mc.`source_id`
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
    // Create a service instance and send requests to jacq-servlet
    try {
        $url = ((!empty($specimen['imgserver_Prot'])) ? $specimen['imgserver_Prot'] : "http") . '://'
            . $specimen['imgserver_IP']
            . (($specimen['img_service_directory']) ? '/' . $specimen['img_service_directory'] . '/' : '/')
            . 'jacq-servlet/ImageServer';
        $service = new \JsonRPC\Client($url);
        $pics = $service->execute('listResources',
            [
                $specimen['key'],
                [
                    $filename,
                    $filename . "_%",
                    $filename . "A",
                    $filename . "B",
                    "tab_" . $this->specimenID,
                    "obs_" . $this->specimenID,
                    "tab_" . $this->specimenID . "_%",
                    "obs_" . $this->specimenID . "_%"
                ]
            ]);
        if (count($pics ?? array()) > 0) {
            foreach ($pics as $pic) {
                $images[] = 'filename=' . rawurlencode(basename($pic)) . '&sid=' . $this->specimenID;
            }
        }
    }
    catch( Exception $e ) {
        // something went wrong so we fall back to the original filename
        $images[0] = 'filename=' . rawurlencode(basename($filename)) . '&sid=' . $this->specimenID;
    }

    foreach ($images as $image) {
        $this->imageLinks[] = 'https://www.jacq.org/image.php?' . $image . '&method=show';
        $this->fileLinks[]  = 'https://www.jacq.org/image.php?' . $image . '&method=download&format=jpeg2000';
    }
}

}
