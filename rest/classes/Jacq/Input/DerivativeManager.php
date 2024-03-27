<?php

namespace Jacq\Input;

use mysqli;

class DerivativeManager
{

private mysqli $db;
private ClassificationManager $classificationManager;

public function __construct(mysqli $db, ClassificationManager $classificationManager)
{
    $this->db                    = $db;
    $this->classificationManager = $classificationManager;
}

public function getList(array $criteria = array())
{
    $constraints = array();
    if (!empty($criteria['organisationIds'])) {
        $constraints[] = "organisation_id IN (" . implode(', ', $criteria['organisationIds']) . ")";
    }
    if (isset($criteria['separated'])) {
        $constraints[] = "separated = " . intval($criteria['separated']);
    }

    $ret = array();
    $rows = $this->db->query("(SELECT * FROM view_botanical_object_living " . (($constraints) ? "WHERE " . implode(" AND ", $constraints) : '') . ") 
                              UNION
                              (SELECT * FROM view_botanical_object_vegetative " . (($constraints) ? "WHERE " . implode(" AND ", $constraints) : '') . ")")
                     ->fetch_all(MYSQLI_ASSOC);
    $protolog[0] = null;  // for empty $family['source_id']
    foreach ($rows as $row) {
        $name             = $this->getScientificName($row['scientific_name_id']);
        $family           = $this->classificationManager->getFamily($row['scientific_name_id']);
        $derivative       = $this->db->query("SELECT count, price FROM tbl_derivative WHERE derivative_id = {$row['derivative_id']}")->fetch_assoc();
        $botanicalObject  = $this->db->query("SELECT * FROM tbl_botanical_object WHERE id = {$row['botanical_object_id']}")->fetch_assoc();
        $livingPlant      = $this->db->query("SELECT * FROM tbl_living_plant WHERE id = {$row['derivative_id']}")->fetch_assoc();
        $scNameInfo       = $this->db->query("SELECT * FROM tbl_scientific_name_information WHERE scientific_name_id = {$row['scientific_name_id']}")->fetch_assoc();
        $acquisition      = $this->db->query("SELECT ae.number, ae.annotation, ad.year, ad.month, ad.day, ad.custom,
                                               lc.altitude_min, lc.altitude_max, 
                                               lc.latitude_half AS lat_NS, lc.latitude_degrees AS lat_d, lc.latitude_minutes AS lat_m, lc.latitude_seconds AS lat_s,
                                               lc.longitude_half AS lon_EW, lc.longitude_degrees AS lon_d, lc.longitude_minutes AS lon_m, lc.longitude_seconds AS lon_s
                                              FROM tbl_acquisition_event ae
                                               LEFT JOIN tbl_acquisition_date ad     ON ad.id = ae.acquisition_date_id
                                               LEFT JOIN tbl_location_coordinates lc ON lc.id = ae.location_coordinates_id
                                              WHERE ae.id = {$botanicalObject['acquisition_event_id']}")
                                     ->fetch_assoc();
        $acPersons        = $this->db->query("SELECT  p.name
                                              FROM tbl_acquisition_event_person aep
                                               LEFT JOIN tbl_person p ON p.id = aep.person_id
                                              WHERE acquisition_event_id = {$botanicalObject['acquisition_event_id']}")
                                     ->fetch_all(MYSQLI_ASSOC);
        if (!empty($family['source_id']) && empty($protolog[$family['source_id']])) { // use caching
            $protolog[$family['source_id']] = $this->db->query("SELECT protolog FROM view_protolog WHERE citation_id = {$family['source_id']}")->fetch_assoc()['protolog'];
        }
        if (!empty($livingPlant['label_synonym_scientific_name_id'])) {
            $buffer = $this->getScientificName($livingPlant['label_synonym_scientific_name_id']);
            $labelSynonymScientificName = $buffer['scientific_name'];
        } else {
            $labelSynonymScientificName = null;
        }
        if (!empty($livingPlant['index_seminum_type_id'])) {
            $buffer = $this->db->query("SELECT type FROM tbl_index_seminum_type WHERE id = {$livingPlant['index_seminum_type_id']}")->fetch_assoc();
            $indexSeminumType = $buffer['type'];
        } else {
            $indexSeminumType = null;
        }
        if (!empty($acPersons)) {
            $collectorsList = array();
            foreach ($acPersons as $acPerson) {
                $collectorsList[] = $acPerson['name'];
            }
        } else {
            $collectorsList = null;
        }
        if (!empty($acquisition['lat_d']) && !empty($acquisition['lat_m']) && !empty($acquisition['lat_s'])) {
            $lat = (($acquisition['lat_NS'] == 'S') ? '-' : '') . "{$acquisition['lat_d']}.{$acquisition['lat_m']}.{$acquisition['lat_s']}";
        } else {
            $lat = null;
        }
        if (!empty($acquisition['lon_d']) && !empty($acquisition['lon_m']) && !empty($acquisition['lon_s'])) {
            $lon = (($acquisition['lon_EW'] == 'W') ? '-' : '') . "{$acquisition['lon_d']}.{$acquisition['lon_m']}.{$acquisition['lon_s']}";
        } else {
            $lon = null;
        }
        $ret[] = array(
            'ID'                                 => $row['derivative_id'],
            'Wissenschaftlicher Name'            => $row['scientific_name'],
            'Standort'                           => $row['organisation_description'],
            'Akzessionsnummer'                   => $row['accession_number'],
            'Ort'                                => $row['gathering_location'],
            'Platznummer'                        => $row['place_number'],
            'Familie'                            => $family['scientificName'] ?? null,
            'Synonym für Etikett'                => $labelSynonymScientificName,
            'Volksnamen'                         => $scNameInfo['common_names'] ?? null,
            'Verbreitung'                        => $scNameInfo['spatial_distribution'] ?? null,
            'Familie Referenz'                   => $protolog[($family['source_id'] ?? 0)] ?? null,
            'Anmerkung für Etikett'              => $row['label_annotation'],
            'Wissenschaftlicher Name ohne Autor' => $name['scientific_name_no_author'] ?? null,
            'Wissenschaftlicher Name Author'     => $name['scientific_name_author'] ?? null,
            'Familie ohne Author'                => $family['scientificNameNoAuthor'] ?? null,
            'Familie Author'                     => $family['scientificNameAuthor'] ?? null,
            'Art'                                => $indexSeminumType,
            'IPEN Nummer'                        => $row['ipen_number'],
            'Lebensraum'                         => $botanicalObject['habitat'],
            'Sammelnummer'                       => $acquisition['number'],
            'Altitude Min'                       => $acquisition['altitude_min'],
            'Altitude Max'                       => $acquisition['altitude_max'],
            'Breitengrad'                        => $lat,
            'Längengrad'                         => $lon,
            'Sammeldatum'                        => ($acquisition['custom']) ?: "{$acquisition['day']}.{$acquisition['month']}.{$acquisition['year']}",
            'Sammler-Name(n)'                    => ($collectorsList) ? implode(',', $collectorsList) : null,
            'Sorte'                              => $row['cultivar_name'],
            'Anzahl'                             => $derivative['count'],
            'Preis'                              => $derivative['price']
        );
    }

    return $ret;
}

// ---------------------------------------
// ---------- private functions ----------
// ---------------------------------------

private function getScientificName(int $scientificNameId): bool|array|null
{
    $row = $this->db->query("SELECT scientific_name_id, scientific_name, scientific_name_no_author, scientific_name_author
                             FROM view_scientificName
                             WHERE scientific_name_id = $scientificNameId")
                    ->fetch_assoc();
    return $row;
}

}
