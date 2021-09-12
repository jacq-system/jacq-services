<?php
class IiifManifestMetadataMapper extends Mapper
{

protected $specimenID;

/**
 * holds the metadata to be returned
 *
 * @var array metadata
 */
protected $meta = array();

/**
 * holds additional metadata with doesn't exist within the metadata-array
 *
 * @var array additional metadata
 */
protected $addtlData = array();

/**
 * class constructor. Prepares metadata to be returned by the specific methods
 *
 * @param mysqli $db instance of mysqli-database
 * @param int $specimenID ID of specimen
 * @param array $metadata already existing metadata in manifest (optional)
 */
public function __construct(mysqli $db, $specimenID, $metadata = array())
{
    parent::__construct($db);

    $this->specimenID = intval($specimenID);

    $this->meta = $metadata;

    // prepare metadata to be sent back
    $row_sid = $this->db->query("SELECT stableIdentifier
                                 FROM tbl_specimens_stblid
                                 WHERE specimen_ID = $specimenID
                                 ORDER BY timestamp DESC
                                 LIMIT 1")
                        ->fetch_assoc();

    $row = $this->db->query("SELECT herbar_view.GetScientificName(s.taxonID, 0) AS sciName, tg.genus, te.epithet,
                              s.HerbNummer, s.observation, s.Datum, s.Datum2, s.taxon_alt, s.Fundort, s.Nummer, s.alt_number,
                              c.Sammler, c.WIKIDATA_ID, c.HUH_ID, c.VIAF_ID, c2.Sammler_2,
                              md.OwnerOrganizationName, md.OwnerOrganizationAbbrev, md.OwnerLogoURI, md.LicenseURI,
                              te.epithet,
                              tg.genus,
                              tf.family,
                              gn.nation_engl, gn.iso_alpha_3_code
                             FROM tbl_specimens s
                              LEFT JOIN tbl_collector c               ON c.SammlerID     = s.SammlerID
                              LEFT JOIN tbl_collector_2 c2            ON c2.Sammler_2ID  = s.Sammler_2ID
                              LEFT JOIN tbl_tax_species ts            ON ts.taxonID      = s.taxonID
                              LEFT JOIN tbl_tax_rank ttr              ON ttr.tax_rankID  = ts.tax_rankID
                              LEFT JOIN tbl_management_collections mc ON mc.collectionID = s.collectionID
                              LEFT JOIN metadata md                   ON md.MetadataID   = mc.source_id
                              LEFT JOIN tbl_tax_epithets te           ON te.epithetID    = ts.speciesID
                              LEFT JOIN tbl_tax_genera tg             ON tg.genID        = ts.genID
                              LEFT JOIN tbl_tax_families tf           ON tf.familyID     = tg.familyID
                              LEFT JOIN tbl_geo_nation gn             ON gn.nationID     = s.NationID
                             WHERE s.specimen_ID = $specimenID")
                        ->fetch_assoc();

    $basisOfRecord = (($row['observation'] > 0) ? "HumanObservation" : "PreservedSpecimen");

    /**
     * CollectorTeam
     */
    $CollectorTeam = $row['Sammler'];
    if (strstr($row['Sammler_2'], "et al.") || strstr($row['Sammler_2'], "alii")) {
        $CollectorTeam .= " et al.";
    } elseif ($row['Sammler_2']) {
        $parts = explode(',', $row['Sammler_2']);           // some people forget the final "&"
        if (count($parts) > 2) {                            // so we have to use an alternative way
            $CollectorTeam .= ", " . $row['Sammler_2'];
        } else {
            $CollectorTeam .= " & " . $row['Sammler_2'];
        }
    }

    if (trim($row['Datum']) == "s.d.") {
        $created = '';
    } else {
        $created = trim($row['Datum']);
        if ($created) {
            if (trim($row['Datum2'])) {
                $created .= " - " . trim($row['Datum2']);
            }
        } else {
            $created = trim($row['Datum2']);
        }
    }

    $this->addtlData['description'] = "A {$basisOfRecord} of " . $row['sciName'] . " collected by {$CollectorTeam}";
    $this->addtlData['label']       = $row['sciName'];
    $this->addtlData['attribution'] = $row['LicenseURI'];
    $this->addtlData['logo']        = array('@id' => $row['OwnerLogoURI']);

    $this->addToMeta('dc:title',       $row['sciName']);
    $this->addToMeta('dc:description', $this->addtlData['description']);
    $this->addToMeta('dc:creator',     $CollectorTeam);
    $this->addToMeta('dc:created',     $created);
    $this->addToMeta('dc:type',        $basisOfRecord);

    $this->addToMeta('dwc:materialSampleID',        $row_sid['stableIdentifier']);
    $this->addToMeta('dwc:basisOfRecord',           $basisOfRecord);
    $this->addToMeta('dwc:collectionCode',          $row['OwnerOrganizationAbbrev']);
    $this->addToMeta('dwc:catalogNumber',           ($row['HerbNummer']) ? $row['HerbNummer'] : ('JACQ-ID ' . $row['specimen_ID']));
    $this->addToMeta('dwc:scientificName',          $row['sciName']);
    $this->addToMeta('dwc:previousIdentifications', $row['taxon_alt']);
    $this->addToMeta('dwc:family',                  $row['family']);
    $this->addToMeta('dwc:genus',                   $row['genus']);
    $this->addToMeta('dwc:specificEpithet',         $row['epithet']);
    $this->addToMeta('dwc:country',                 $row['nation_engl']);
    $this->addToMeta('dwc:countryCode',             $row['iso_alpha_3_code']);
    $this->addToMeta('dwc:locality',                $row['Fundort']);
    $this->addToMeta('dwc:eventDate',               $created);
    $this->addToMeta('dwc:recordNumber',            ($row['HerbNummer']) ? $row['HerbNummer'] : ('JACQ-ID ' . $row['specimen_ID']));
    $this->addToMeta('dwc:recordedBy',              $CollectorTeam);
    $this->addToMeta('dwc:fieldNumber',             trim($row['Nummer'] . ' ' . $row['alt_number']));

    $this->addToMeta('CETAF_ID',          $row_sid['stableIdentifier']);
    $this->addToMeta('dwciri:recordedBy', $row['WIKIDATA_ID']);
    $this->addToMetaIfSet('owl:sameAs',   $row['HUH_ID']);
    $this->addToMetaIfSet('owl:sameAs',   $row['VIAF_ID']);

}

/**
 * get array of metadata for this specimen
 *
 * @return array metadata
 */
public function getMetadata()
{
    return $this->meta;
}

/**
 * get array of metadata for this specimen, where values are not empty
 *
 * @return array metadata
 */
public function getMetadataWithValue()
{
    $result = array();
    foreach ($this->meta as $key => $row) {
        if (!empty($row['value'])) {
            $result[] = $row;
        }
    }
    return $result;
}

/**
 * get description of this specimen
 *
 * @return string description
 */
public function getDescription()
{
    return $this->addtlData['description'];
}

/**
 * get label of this specimen
 *
 * @return string label
 */
public function getLabel()
{
    return $this->addtlData['label'];
}

/**
 * get the attribution of this specimen
 *
 * @return string attribution
 */
public function getAttribution()
{
    return $this->addtlData['attribution'];
}

/**
 * get the logo data of this specimen
 *
 * @return string logo URI
 */
public function getLogo()
{
    return $this->addtlData['logo'];
}


////////////////////////////// private functions //////////////////////////////
private function addToMeta($label, $value)
{
    $this->meta[] = array('label' => $label,
                          'value' => $value);
}

private function addToMetaIfSet($label, $value)
{
    if (!empty($value)) {
        $this->addToMeta($label, $value);
    }
}

}