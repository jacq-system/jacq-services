<?php
class ImageLinkMapper extends Mapper
{

    protected int $specimenID = 0;
    protected string $imageLink = '';
    protected string $fileLink = '';

public function __construct(mysqli $db, int $specimenID)
{
    parent::__construct($db);

    if ($specimenID == 0) {
        return;  // nothing to look for, so just stop
    }
    $this->specimenID = $specimenID;

    $specimen = $this->db->query("SELECT s.digital_image, s.digital_image_obs, s.`HerbNummer`, s.`Bemerkungen`, 
                                   id.`imgserver_Prot`, id.`imgserver_IP`, id.`imgserver_type`, id.`img_service_directory`, id.`is_djatoka`, id.`HerbNummerNrDigits`,
                                   id.iiif_capable, id.iiif_proxy, id.iiif_dir,
                                   mc.`coll_short_prj`, mc.`source_id`, mc.`collectionID`, mc.`picture_filename`,
                                   pc.specimenID as phaidra_sid
                                  FROM `tbl_specimens` s
                                   LEFT JOIN `tbl_management_collections` mc ON mc.`collectionID` = s.`collectionID`
                                   LEFT JOIN `tbl_img_definition` id ON id.`source_id_fk` = mc.`source_id`
                                   LEFT JOIN `herbar_pictures`.`phaidra_cache` pc ON pc.specimenID = s.specimen_ID
                                  WHERE s.`specimen_ID` = $this->specimenID")
                         ->fetch_assoc();

    if (!empty($specimen['digital_image']) || !empty($specimen['digital_image_obs'])) {
        if ($specimen['phaidra_sid']) {
            // for now, special treatment for phaidra is needed when wu has images
            $this->imageLink = 'https://' . $specimen['iiif_proxy'] . $specimen['iiif_dir'] . '/'
                             . '?manifest=https://services.jacq.org/jacq-services/rest/iiif/manifest/' . $specimen['phaidra_sid'];
            $iiif = new IiifMapper($this->db);
            $manifest = $iiif->getImageManifest($specimen['phaidra_sid']);
            if ($manifest) {
                foreach ($manifest['sequences'] as $sequence) {
                    foreach ($sequence['canvases'] as $canvas) {
                        foreach ($canvas['images'] as $image) {
                            $this->fileLink = 'https://www.jacq.org/downloadPhaidra.php?filename='
                                            . sprintf("WU%0" . $specimen['HerbNummerNrDigits'] . ".0f", str_replace('-', '', $specimen['HerbNummer']))
                                            . ".jpg&url="
                                            . $image['resource']['service']['@id']
                                            . "/full/full/0/default.jpg";
                            break 3;  // just use the first image
                        }
                    }
                }
            }
        } elseif ($specimen['iiif_capable']) {
            // TODO
        } elseif ($specimen['imgserver_type'] == 'bgbm') {
            // TODO
        } elseif ($specimen['imgserver_type'] == 'djatoka') {
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
            $first_part = 'https://www.jacq.org/image.php?' . 'filename=' . rawurlencode(basename($filename)) . '&sid=' . $specimen['specimen_ID'];
            $this->imageLink = $first_part. '&method=show';
            $this->fileLink  = $first_part . '&method=download&format=jpeg2000';
        }
    }
}

public function getFirstImageShowLink()
{
    return $this->imageLink;
}

public function getFirstImageDownloadLink()
{
    return $this->fileLink;
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

}
