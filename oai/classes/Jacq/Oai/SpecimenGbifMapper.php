<?php

namespace Jacq\Oai;

use mysqli;

class SpecimenGbifMapper implements SpecimenInterface
{

protected mysqli $db;
protected int $specimenID = 0;
protected bool $isValid = false;

/**
 * holds the specimen properties
 *
 * @var array properties
 */
protected array $properties = array();

/**
 * class constructor. Prepares properties to be returned by the specific methods
 *
 * @param mysqli $db instance of mysqli-database
 * @param int $id specimen-ID (int)
 */
public function __construct(mysqli $db, int $id)
{
    $this->db = $db;

    if (empty($id)) {
        return;  // nothing to look for, so just stop
    }

    $this->specimenID = $id;

    /**
     * get all properties of the specimen
     */
    $row = $this->db->query("SELECT s.aktualdatum, s.json, so.source_id, so.OwnerOrganizationName, so.LicenseURI, so.LicensesDetails
                             FROM gbif_cache.specimens s 
                              JOIN gbif_cache.sources so ON so.source_id = s.source_id
                             WHERE s.specimen_ID = $this->specimenID")
                    ->fetch_assoc();

    if (!empty($row)) {
        $this->isValid = true;  // we have found valid data
        $this->properties = json_decode($row['json'], true);

        $this->properties['specimenID']            = $this->specimenID;
        $this->properties['aktualdatum']           = $row['aktualdatum'];
        $this->properties['source_id']             = $row['source_id'];
        $this->properties['OwnerOrganizationName'] = $row['OwnerOrganizationName'];
        $this->properties['LicenseURI']            = $row['LicenseURI'];
        $this->properties['LicensesDetails']       = $row['LicensesDetails'];

        if (empty($row['eventDate'])) {
            $this->properties['eventDate'] = '';    // in case eventDate does not exist in the answer of gbif
        }
    }
}

/**
 * get all properties of this specimen
 *
 * @return array properties
 */
public function getProperties(): array
{
    return $this->properties;
}

/**
 * get a single property
 *
 * @param string $property
 * @return mixed
 */
public function getProperty(string $property): mixed
{
    return $this->properties[$property] ?? '';
}

/**
 * get the ID of this specimen
 *
 * @return string specimenID with a leading "g"
 */
public function getSpecimenID(): string
{
    return 'g' . $this->specimenID;
}

/**
 * do we have valid data?
 *
 * @return bool isValid
 */
public function isValid(): bool
{
    return $this->isValid;
}

/**
 * get the description of this specimen
 *
 * @return string description
 */
public function getDescription(): string
{
    return "A PreservedSpecimen"
         . " of " . $this->properties['scientificName'];
}

/**
 * get the properties of this specimen with Dublin Core Names (dc:...)
 *
 * @return array dc-data
 */
public function getDC(): array
{
    if ($this->isValid) {
        return array(
            'dc:title'       => $this->properties['scientificName'],
            'dc:description' => $this->getDescription(),
            'dc:creator'     => $this->properties['recordedBy'],
            'dc:created'     => $this->properties['eventDate'],
            'dc:type'        => "PreservedSpecimen");
    } else {
        return array();
    }
}

/**
 * get the properties of this specimen according to the Europeana Data Model
 *
 * @return array edm-data
 */
public function getEDM(): array
{
    $edm = array();

    if ($this->isValid) {
        // see https://wissen.kulturpool.at/books/europeana-data-model-edm/page/kurzreferenz-edm-pflichtfelder

        // see https://wissen.kulturpool.at/books/europeana-data-model-edm/page/pflichtfelder-zum-digitalen-objekt
        $edm['ore:Aggregation'] = array(
            'rdf:about'         => $this->properties['occurrenceID'],
            'edm:aggregatedCHO' => $this->properties['occurrenceID'] . '#CHO',
            'edm:dataProvider'  => $this->properties['OwnerOrganizationName'],
            'edm:isShownAt'     => $this->properties['occurrenceID'],
            'edm:isShownBy'     => $this->properties['media'][0]['identifier'],
            'edm:rights'        => $this->properties['LicenseURI'],
            'edm:object'        => '',  //unused
        );

        // see https://wissen.kulturpool.at/books/europeana-data-model-edm/page/pflichtfelder-zum-kulturgut
        $edm['edm:ProvidedCHO'] = array(
            'rdf:about'         => $edm['ore:Aggregation']['edm:aggregatedCHO'],
            'dc:title'          => $this->properties['scientificName'],
            'dc:description'    => $this->getDescription(),
            'dc:identifier'     => $this->properties['occurrenceID'],
            'dc:language'       => 'und',   // language in dataset is undetermined
            'edm:type'          => 'IMAGE',
            //'dc:subject'      unused
            'dc:type'           => "http://rs.tdwg.org/dwc/terms/PreservedSpecimen",
            'dcterms:spatial'   => '',  //unused
            //dcterms:temporal  unused
            'dc:date'           => $this->properties['eventDate'],
            'dc:creator'        => $this->properties['recordedBy'],
        );

        // see https://wissen.kulturpool.at/books/europeana-data-model-edm/page/pflichtfelder-zum-digitalen-objekt
        $edm['edm:WebResource'] = array(
            array(
                'rdf:about'         => $edm['ore:Aggregation']['edm:isShownAt'],
                'dc:rights'         => '',  //unused
                'edm:rights'        => '',  //unused
                'dc:type'           => '',  //unused
            ),
            array(
                'rdf:about'         => $edm['ore:Aggregation']['edm:isShownBy'],
                'dc:rights'         => $this->properties['OwnerOrganizationName'],
                'edm:rights'        => $this->properties['LicenseURI'],
                'dc:type'           => $this->properties['media'][0]['type'],
            ),
        );

        if (count($this->properties['media']) > 1) {
            for ($i = 1; $i < count($this->properties['media']); $i++) {
                $edm['ore:Aggregation']['edm:hasView'][] = $this->properties['media'][$i]['identifier'];
                $edm['edm:WebResource'][] = array(
                    'rdf:about'  => $this->properties['media'][$i]['identifier'],
                    'dc:rights'  => $this->properties['OwnerOrganizationName'],
                    'edm:rights' => $this->properties['LicenseURI'],
                    'dc:type'    => $this->properties['media'][$i]['type'],
                );
            }
        }
    }

    return $edm;
}

// ---------------------------------------
// ---------- private functions ----------
// ---------------------------------------


}
