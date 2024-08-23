<?php

namespace Jacq\Oai;

use mysqli;

class SpecimenMapper implements SpecimenInterface
{

    protected mysqli $db;
    protected int $specimenID = 0;
    protected bool $isValid = false;
    private string $baseURL = 'https://services.jacq.org/jacq-services/rest';

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
     * @param mixed $id either specimen-ID (int) or stable Identifier (string)
     */
    public function __construct(mysqli $db, mixed $id)
    {
        $this->db = $db;

        if (empty($id)) {
            return;  // nothing to look for, so just stop
        }

        if (is_numeric($id)) {
            $this->specimenID = intval($id);
            //get the latest stable identifier
            $row_sid = $this->db->query("SELECT stableIdentifier
                                     FROM tbl_specimens_stblid
                                     WHERE specimen_ID = $this->specimenID
                                     ORDER BY timestamp DESC
                                     LIMIT 1")
                                ->fetch_assoc();
            if (empty($row_sid['stableIdentifier'])) {
                return;  // no stable identifier exists
            } else {
                $this->properties['stableIdentifier'] = $row_sid['stableIdentifier'];
            }
        } else {
            $row_sid = $this->db->query("SELECT specimen_ID
                                     FROM tbl_specimens_stblid
                                     WHERE stableIdentifier = '$id'")
                                ->fetch_assoc();
            if (empty($row_sid['specimen_ID'])) {
                return;  // no stable identifier exists
            } else {
                $this->specimenID = $row_sid['specimen_ID'];
                $this->properties['stableIdentifier'] = $id;
            }
        }

        /**
         * get all other properties of the specimen if it is accessible
         */
        $row = $this->db->query("SELECT herbar_view.GetScientificName(s.taxonID, 0) AS sciName, tf.family, tg.genus, te.epithet,
                              s.HerbNummer, s.CollNummer, s.observation, s.Datum, s.Datum2, s.taxon_alt, s.Fundort, s.Nummer, s.alt_number,
                              s.Coord_W, s.W_Min, s.W_Sec, s.Coord_N, s.N_Min, s.N_Sec, s.Coord_S, s.S_Min, s.S_Sec, s.Coord_E, s.E_Min, s.E_Sec,
                              s.digital_image, s.digital_image_obs,
                              s.aktualdatum,
                              c.Sammler, c.WIKIDATA_ID, c.HUH_ID, c.VIAF_ID, c.ORCID, c2.Sammler_2,
                              md.OwnerOrganizationName, md.OwnerOrganizationAbbrev, md.OwnerLogoURI, md.LicenseURI, md.LicensesDetails,
                              mc.source_id,
                              ss.series,
                              gn.nation_engl, gn.iso_alpha_3_code, gp.provinz
                             FROM tbl_specimens s
                              LEFT JOIN tbl_collector c               ON c.SammlerID     = s.SammlerID
                              LEFT JOIN tbl_collector_2 c2            ON c2.Sammler_2ID  = s.Sammler_2ID
                              LEFT JOIN tbl_tax_species ts            ON ts.taxonID      = s.taxonID
                              LEFT JOIN tbl_tax_rank ttr              ON ttr.tax_rankID  = ts.tax_rankID
                              LEFT JOIN tbl_management_collections mc ON mc.collectionID = s.collectionID
                              LEFT JOIN metadata md                   ON md.MetadataID   = mc.source_id
                              LEFT JOIN tbl_specimens_series ss       ON ss.seriesID     = s.seriesID
                              LEFT JOIN tbl_tax_epithets te           ON te.epithetID    = ts.speciesID
                              LEFT JOIN tbl_tax_genera tg             ON tg.genID        = ts.genID
                              LEFT JOIN tbl_tax_families tf           ON tf.familyID     = tg.familyID
                              LEFT JOIN tbl_geo_nation gn             ON gn.nationID     = s.NationID
                              LEFT JOIN tbl_geo_province gp           ON gp.provinceID   = s.provinceID
                             WHERE s.specimen_ID = $this->specimenID
                              AND s.`accessible` != '0'")
                        ->fetch_assoc();

        if (!empty($row)) {
            $this->isValid = true;  // we have found valid data
            /**
             * do any neccessary calculations
             */
            /**
             * CollectorTeam
             */
            $collectorTeam = array($row['Sammler']);
            if (str_contains($row['Sammler_2'], "et al.") || str_contains($row['Sammler_2'], "alii")) {
                $collectorTeam[] = "et al.";
            } elseif ($row['Sammler_2']) {
                $collectors = explode(', ', $row['Sammler_2']);
                foreach ($collectors as $collector) {
                    if (str_contains($collector, "&")) {
                        $parts = explode("&", $collector);
                        $collectorTeam[] = trim($parts[0]);
                        $collectorTeam[] = trim($parts[1]);
                    } else {
                        $collectorTeam[] = trim($collector);
                    }
                }
            }
            /**
             * created
             */
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

            if ($row['Coord_S'] > 0 || $row['S_Min'] > 0 || $row['S_Sec'] > 0) {
                $decimalLatitude = -round($row['Coord_S'] + $row['S_Min'] / 60 + $row['S_Sec'] / 3600, 5);
                $verbatimLatitude = $row['Coord_S'] . "d " . (($row['S_Min']) ?: '?') . "m " . (($row['S_Sec']) ?: '?') . 's S';
            } else if ($row['Coord_N'] > 0 || $row['N_Min'] > 0 || $row['N_Sec'] > 0) {
                $decimalLatitude = round($row['Coord_N'] + $row['N_Min'] / 60 + $row['N_Sec'] / 3600, 5);
                $verbatimLatitude = $row['Coord_N'] . "d " . (($row['N_Min']) ?: '?') . "m " . (($row['N_Sec']) ?: '?') . 's N';
            } else {
                $decimalLatitude = null;
                $verbatimLatitude = '';
            }
            if ($row['Coord_W'] > 0 || $row['W_Min'] > 0 || $row['W_Sec'] > 0) {
                $decimalLongitude = -round($row['Coord_W'] + $row['W_Min'] / 60 + $row['W_Sec'] / 3600, 5);
                $verbatimLongitude = $row['Coord_W'] . "d " . (($row['W_Min']) ?: '?') . "m " . (($row['W_Sec']) ?: '?') . 's W';
            } else if ($row['Coord_E'] > 0 || $row['E_Min'] > 0 || $row['E_Sec'] > 0) {
                $decimalLongitude = round($row['Coord_E'] + $row['E_Min'] / 60 + $row['E_Sec'] / 3600, 5);
                $verbatimLongitude = $row['Coord_E'] . "d " . (($row['E_Min']) ?: '?') . "m " . (($row['E_Sec']) ?: '?') . 's E';
            } else {
                $decimalLongitude = null;
                $verbatimLongitude = '';
            }

            $spatial = trim($row['nation_engl']);
            if (!empty(trim($row['provinz']))) {
                $spatial .= (($spatial) ? ', ' : '') . trim($row['provinz']);
            }
            if (!empty(trim($row['Fundort']))) {
                $spatial .= (($spatial) ? ', ' : '') . trim($row['Fundort']);
            }

            if (!empty($row['digital_image']) || !empty($row['digital_image_obs'])) {
                $firstImageLink = $this->baseURL . "/images/show/$this->specimenID";
                $firstImageDownloadLink = $this->baseURL . "/images/download/$this->specimenID";
            } else {
                $firstImageLink = $firstImageDownloadLink = '';
            }


            /**
             * store all properties
             */
            $this->properties['specimenID']              = $this->specimenID;
            $this->properties['source_id']               = $row['source_id'];
            $this->properties['scientificName']          = $row['sciName'];
            $this->properties['family']                  = $row['family'];
            $this->properties['genus']                   = $row['genus'];
            $this->properties['epithet']                 = $row['epithet'];
            $this->properties['HerbNummer']              = $row['HerbNummer'];
            $this->properties['CollNummer']              = $row['CollNummer'];
            $this->properties['observation']             = $row['observation'];
            $this->properties['taxon_alt']               = $row['taxon_alt'];
            $this->properties['Fundort']                 = $row['Fundort'];
            $this->properties['decimalLatitude']         = $decimalLatitude;
            $this->properties['decimalLongitude']        = $decimalLongitude;
            $this->properties['verbatimLatitude']        = $verbatimLatitude;
            $this->properties['verbatimLongitude']       = $verbatimLongitude;
            $this->properties['collectorTeam']           = $collectorTeam;
            $this->properties['created']                 = $created;
            $this->properties['Nummer']                  = $row['Nummer'];
            $this->properties['series']                  = $row['series'];
            $this->properties['alt_number']              = $row['alt_number'];
            $this->properties['WIKIDATA_ID']             = $row['WIKIDATA_ID'];
            $this->properties['HUH_ID']                  = $row['HUH_ID'];
            $this->properties['VIAF_ID']                 = $row['VIAF_ID'];
            $this->properties['ORCID']                   = $row['ORCID'];
            $this->properties['OwnerOrganizationName']   = $row['OwnerOrganizationName'];
            $this->properties['OwnerOrganizationAbbrev'] = $row['OwnerOrganizationAbbrev'];
            $this->properties['OwnerLogoURI']            = $row['OwnerLogoURI'] ?? '';
            $this->properties['LicenseURI']              = $row['LicenseURI'] ?? '';
            $this->properties['LicensesDetails']         = $row['LicensesDetails'] ?? '';
            $this->properties['nation_engl']             = $row['nation_engl'];
            $this->properties['iso_alpha_3_code']        = $row['iso_alpha_3_code'];
            $this->properties['spatial']                 = $spatial;
            $this->properties['aktualdatum']             = $row['aktualdatum'];
            $this->properties['image']                   = $firstImageLink;
            $this->properties['downloadImage']           = $firstImageDownloadLink;
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
     * get a single property with its JACQ-name
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
     * @return int specimenID
     */
    public function getSpecimenID(): int
    {
        return $this->specimenID;
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
        return "A " . (($this->properties['observation'] > 0) ? "HumanObservation" : "PreservedSpecimen")
            . " of " . $this->properties['scientificName'] . " collected by " . $this->collapseCollectorTeam();
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
                'dc:creator'     => $this->properties['collectorTeam'],
                'dc:created'     => $this->properties['created'],
                'dc:type'        => ($this->properties['observation'] > 0) ? "HumanObservation" : "PreservedSpecimen");
        } else {
            return array();
        }
    }

    /**
     * get the properties of this specimen with Darwin Core Names (dwc:...)
     *
     * @return array dwc-data
     */
    public function getDWC(): array
    {
        if ($this->isValid) {
            return array(
                'dwc:materialSampleID'        => $this->properties['stableIdentifier'],
                'dwc:basisOfRecord'           => ($this->properties['observation'] > 0) ? "HumanObservation" : "PreservedSpecimen",
                'dwc:collectionCode'          => $this->properties['OwnerOrganizationAbbrev'],
                'dwc:catalogNumber'           => ($this->properties['HerbNummer']) ?: ('JACQ-ID ' . $this->properties['specimenID']),
                'dwc:scientificName'          => $this->properties['scientificName'],
                'dwc:previousIdentifications' => $this->properties['taxon_alt'],
                'dwc:family'                  => $this->properties['family'],
                'dwc:genus'                   => $this->properties['genus'],
                'dwc:specificEpithet'         => $this->properties['epithet'],
                'dwc:country'                 => $this->properties['nation_engl'],
                'dwc:countryCode'             => $this->properties['iso_alpha_3_code'],
                'dwc:locality'                => $this->properties['Fundort'],
                'dwc:decimalLatitude'         => $this->properties['decimalLatitude'],
                'dwc:decimalLongitude'        => $this->properties['decimalLongitude'],
                'dwc:verbatimLatitude'        => $this->properties['verbatimLatitude'],
                'dwc:verbatimLongitude'       => $this->properties['verbatimLongitude'],
                'dwc:eventDate'               => $this->properties['created'],
                'dwc:recordNumber'            => ($this->properties['HerbNummer']) ?: ('JACQ-ID ' . $this->properties['specimenID']),
                'dwc:recordedBy'              => $this->properties['collectorTeam'],
                'dwc:fieldNumber'             => trim($this->properties['Nummer'] . ' ' . $this->properties['alt_number']));
        } else {
            return array();
        }
    }

    /**
     * get the properties of this specimen according to the Europeana Data Model
     *
     * @return array
     */
    public function getEDM(): array
    {
        $edm = array();

        if ($this->isValid) {
            // see https://wissen.kulturpool.at/books/europeana-data-model-edm/page/kurzreferenz-edm-pflichtfelder

            // see https://wissen.kulturpool.at/books/europeana-data-model-edm/page/pflichtfelder-zum-digitalen-objekt
            $edm['ore:Aggregation'] = array(
                'edm:aggregatedCHO' => $this->properties['stableIdentifier'] . '#CHO',
                'edm:dataProvider'  => $this->properties['OwnerOrganizationName'],
                'edm:isShownAt'     => $this->properties['stableIdentifier'],
                'edm:isShownBy'     => $this->baseURL . "/images/download/" . $this->specimenID . "?withredirect=1",
                'edm:rights'        => $this->properties['LicenseURI'],
                'edm:object'        => $this->baseURL . "/images/europeana/" . $this->specimenID . "?withredirect=1",
            );

            // see https://wissen.kulturpool.at/books/europeana-data-model-edm/page/pflichtfelder-zum-kulturgut
            $edm['edm:ProvidedCHO'] = array(
                'rdf:about'         => $edm['ore:Aggregation']['edm:aggregatedCHO'],
                'dc:title'          => $this->properties['scientificName'],
                'dc:description'    => $this->getDescription(),
                'dc:identifier'     => $this->properties['stableIdentifier'],
                //'dc:language'     unused
                'edm:type'          => 'IMAGE',
                //'dc:subject'      unused
                'dc:type'           => ($this->properties['observation'] > 0) ? "http://rs.tdwg.org/dwc/terms/HumanObservation"
                                                                              : "http://rs.tdwg.org/dwc/terms/PreservedSpecimen",
                'dcterms:spatial'   => $this->properties['spatial'],
                //dcterms:temporal  unused
                'dc:date'           => $this->properties['created'],                // dcterms:created would be wrong
                'dc:creator'        => $this->collapseCollectorTeam(),
            );

            // see https://wissen.kulturpool.at/books/europeana-data-model-edm/page/pflichtfelder-zum-digitalen-objekt
            $edm['edm:WebResource'] = array(
                array(
                    'rdf:about'         => $edm['ore:Aggregation']['edm:isShownAt'],
                ),
                array(
                    'rdf:about'         => $edm['ore:Aggregation']['edm:isShownBy'],
                    'dc:rights'         => $this->properties['OwnerOrganizationName'],
                    'edm:rights'        => $this->properties['LicensesDetails'],
                ),
                array(
                    'rdf:about'         => $edm['ore:Aggregation']['edm:object'],
                    'dc:rights'         => $this->properties['OwnerOrganizationName'],
                    'edm:rights'        => $this->properties['LicensesDetails'],
                ),
            );

        }

        return $edm;
    }

// ---------------------------------------
// ---------- private functions ----------
// ---------------------------------------

    private function collapseCollectorTeam(): string
    {
        $collectorTeam =  $this->properties['collectorTeam'][0];
        $nr = count($this->properties['collectorTeam']);
        if ($nr > 1) {
            for ($i = 1; $i < $nr - 1; $i++) {
                $collectorTeam .= ", " . $this->properties['collectorTeam'][$i];
            }
            $collectorTeam .= " & " . $this->properties['collectorTeam'][$nr - 1];

        }
        return $collectorTeam;
    }

}
