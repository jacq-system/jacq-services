<?php
class SpecimenMapper extends Mapper
{

protected $specimenID = 0;

/**
 * holds the specimen properties
 *
 * @var array properties
 */
protected $properties = array();

/**
 * class constructor. Prepares properties to be returned by the specific methods
 *
 * @param mysqli $db instance of mysqli-database
 * @param int $specimenID ID of specimen
 */
public function __construct(mysqli $db, $specimenID)
{
    parent::__construct($db);

    if (intval($specimenID) == 0) {
        return;  // nothing to look for, so just stop
    }

    /**
     * first get the stable identifier
     */
    $row_sid = $this->db->query("SELECT specimen_ID, stableIdentifier
                                 FROM tbl_specimens_stblid
                                 WHERE specimen_ID = " . intval($specimenID) . "
                                 ORDER BY timestamp DESC
                                 LIMIT 1")
                        ->fetch_assoc();

    if (!empty($row_sid)) {
        $this->properties['stableIdentifier'] = $row_sid['stableIdentifier'];
        $this->specimenID                     = $row_sid['specimen_ID'];
    }

    /**
     * then get all other properties of the specimen
     */
    $row = $this->db->query("SELECT herbar_view.GetScientificName(s.taxonID, 0) AS sciName, tf.family, tg.genus, te.epithet,
                              s.HerbNummer, s.observation, s.Datum, s.Datum2, s.taxon_alt, s.Fundort, s.Nummer, s.alt_number,
                              c.Sammler, c.WIKIDATA_ID, c.HUH_ID, c.VIAF_ID, c2.Sammler_2,
                              md.OwnerOrganizationName, md.OwnerOrganizationAbbrev, md.OwnerLogoURI, md.LicenseURI,
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
                             WHERE s.specimen_ID = {$this->specimenID}")
                        ->fetch_assoc();

    if (!empty($row)) {
        /**
         * CollectorTeam
         */
        $this->properties['collectorTeam'] = $row['Sammler'];
        if (strstr($row['Sammler_2'], "et al.") || strstr($row['Sammler_2'], "alii")) {
            $this->properties['collectorTeam'] .= " et al.";
        } elseif ($row['Sammler_2']) {
            $parts = explode(',', $row['Sammler_2']);           // some people forget the final "&"
            if (count($parts) > 2) {                            // so we have to use an alternative way
                $this->properties['collectorTeam'] .= ", " . $row['Sammler_2'];
            } else {
                $this->properties['collectorTeam'] .= " & " . $row['Sammler_2'];
            }
        }

        /**
         * created
         */
        if (trim($row['Datum']) == "s.d.") {
            $this->properties['created'] = '';
        } else {
            $this->properties['created'] = trim($row['Datum']);
            if ($this->properties['created']) {
                if (trim($row['Datum2'])) {
                    $this->properties['created'] .= " - " . trim($row['Datum2']);
                }
            } else {
                $this->properties['created'] = trim($row['Datum2']);
            }
        }

        /**
         * everything else
         */
        $this->properties['specimenID']              = $this->specimenID;
        $this->properties['scientificName']          = $row['sciName'];
        $this->properties['family']                  = $row['family'];
        $this->properties['genus']                   = $row['genus'];
        $this->properties['epithet']                 = $row['epithet'];
        $this->properties['HerbNummer']              = $row['HerbNummer'];
        $this->properties['observation']             = $row['observation'];
        $this->properties['taxon_alt']               = $row['taxon_alt'];
        $this->properties['Fundort']                 = $row['Fundort'];
        $this->properties['Nummer']                  = $row['Nummer'];
        $this->properties['alt_number']              = $row['alt_number'];
        $this->properties['WIKIDATA_ID']             = $row['WIKIDATA_ID'];
        $this->properties['HUH_ID']                  = $row['HUH_ID'];
        $this->properties['VIAF_ID']                 = $row['VIAF_ID'];
        $this->properties['OwnerOrganizationAbbrev'] = $row['OwnerOrganizationAbbrev'];
        $this->properties['OwnerLogoURI']            = $row['OwnerLogoURI'];
        $this->properties['LicenseURI']              = $row['LicenseURI'];
        $this->properties['nation_engl']             = $row['nation_engl'];
        $this->properties['iso_alpha_3_code']        = $row['iso_alpha_3_code'];
    }
}

/**
 * get all properties of this specimen
 *
 * @return array properties
 */
public function getProperties()
{
    return $this->properties;
}

/**
 * get the ID of this specimen
 *
 * @return int specimenID
 */
public function getSpecimenID()
{
    return $this->specimenID;
}

/**
 * get the description of this specimen
 *
 * @return string description
 */
public function getDescription()
{
    return "A " . (($this->properties['observation'] > 0) ? "HumanObservation" : "PreservedSpecimen")
         . " of " . $this->properties['scientificName'] . " collected by {$this->properties['collectorTeam']}";
}

/**
 * get the label of this specimen
 *
 * @return string label
 */
public function getLabel()
{
    return $this->properties['scientificName'];
}

/**
 * get the attribution of this specimen
 *
 * @return string attribution
 */
public function getAttribution()
{
    return $this->properties['LicenseURI'];
}

/**
 * get the logo URI of this specimen
 *
 * @return string logo URI
 */
public function getLogoURI()
{
    return $this->properties['OwnerLogoURI'];
}

/**
 * get the properties of this specimen with Dublin Core Names (dc:...)
 *
 * @return array dc-data
 */
public function getDC()
{
    $basisOfRecord = ($this->properties['observation'] > 0) ? "HumanObservation" : "PreservedSpecimen";

    return array('dc:title'       => $this->properties['scientificName'],
                 'dc:description' => "A {$basisOfRecord} of " . $this->properties['scientificName'] . " collected by {$this->properties['collectorTeam']}",
                 'dc:creator'     => $this->properties['collectorTeam'],
                 'dc:created'     => $this->properties['created'],
                 'dc:type'        => $basisOfRecord);
}

/**
 * get the properties of this specimen with Darwin Core Names (dwc:...)
 *
 * @return array dwc-data
 */
public function getDWC()
{
    return array('dwc:materialSampleID'        => $this->properties['stableIdentifier'],
                 'dwc:basisOfRecord'           => ($this->properties['observation'] > 0) ? "HumanObservation" : "PreservedSpecimen",
                 'dwc:collectionCode'          => $this->properties['OwnerOrganizationAbbrev'],
                 'dwc:catalogNumber'           => ($this->properties['HerbNummer']) ? $this->properties['HerbNummer'] : ('JACQ-ID ' . $this->properties['specimen_ID']),
                 'dwc:scientificName'          => $this->properties['scientificName'],
                 'dwc:previousIdentifications' => $this->properties['taxon_alt'],
                 'dwc:family'                  => $this->properties['family'],
                 'dwc:genus'                   => $this->properties['genus'],
                 'dwc:specificEpithet'         => $this->properties['epithet'],
                 'dwc:country'                 => $this->properties['nation_engl'],
                 'dwc:countryCode'             => $this->properties['iso_alpha_3_code'],
                 'dwc:locality'                => $this->properties['Fundort'],
                 'dwc:eventDate'               => $this->properties['created'],
                 'dwc:recordNumber'            => ($this->properties['HerbNummer']) ? $this->properties['HerbNummer'] : ('JACQ-ID ' . $this->properties['specimen_ID']),
                 'dwc:recordedBy'              => $this->properties['collectorTeam'],
                 'dwc:fieldNumber'             => trim($this->properties['Nummer'] . ' ' . $this->properties['alt_number']));
}

/**
 * get the properties of this specimen with JACQ Names (jacq:...)
 *
 * @return array jacq-data
 */
public function getJACQ()
{
    $result = array();
    foreach ($this->properties as $key => $value) {
        $result["jacq:{$key}"] = $value;
    }

    return $result;
}


}