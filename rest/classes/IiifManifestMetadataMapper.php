<?php
class IiifManifestMetadataMapper extends Mapper
{

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

    $this->meta = $metadata;

    $specimen = new SpecimenMapper($db, intval($specimenID));
    $specimenProperties = $specimen->getProperties();
    $dcData = $specimen->getDC();
    $dwcData = $specimen->getDWC();
    $basisOfRecord = (($specimenProperties['observation'] > 0) ? "HumanObservation" : "PreservedSpecimen");

    // prepare metadata to be sent back
    $this->addtlData['description'] = "A {$basisOfRecord} of " . $specimenProperties['sciName'] . " collected by {$specimenProperties['collectorTeam']}";
    $this->addtlData['label']       = $specimenProperties['sciName'];
    $this->addtlData['attribution'] = $specimenProperties['LicenseURI'];
    $this->addtlData['logo']        = array('@id' => $specimenProperties['OwnerLogoURI']);

    foreach ($dcData as $key => $value) {
        $this->addToMeta($key, $value);
    }

    foreach ($dwcData as $key => $value) {
        $this->addToMeta($key, $value);
    }

    $this->addToMeta('CETAF_ID',          $specimenProperties['stableIdentifier']);
    $this->addToMeta('dwciri:recordedBy', $specimenProperties['WIKIDATA_ID']);
    $this->addToMetaIfSet('owl:sameAs',   $specimenProperties['HUH_ID']);
    $this->addToMetaIfSet('owl:sameAs',   $specimenProperties['VIAF_ID']);

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