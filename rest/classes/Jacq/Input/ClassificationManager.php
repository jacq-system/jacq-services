<?php

namespace Jacq\Input;

use mysqli;

class ClassificationManager
{

private mysqli $db;     // use this database
private array $sourceIds; // use these source-IDs

public function __construct(mysqli $db, array $sourceIds)
{
    $this->db        = $db;
    $this->sourceIds = $sourceIds;
}

public function getFamily(int $scientificNameId): ?array
{
    foreach ($this->sourceIds as $sourceId) {
        $classification = $this->getClassification($scientificNameId, $sourceId);
        if (empty($classification)) {
            $row = $this->db->query("SELECT name_id, substantive_id, rank_id
                                     FROM mig_nom_name
                                     WHERE name_id = $scientificNameId")
                            ->fetch_assoc();
            if (!empty($row)) {
                // check if this is already a genus entry
                if (empty($row['rank_id']) || $row['rank_id'] == 7) {
                    continue;
                }
                $genusRow = $this->db->query("SELECT name_id
                                              FROM mig_nom_name
                                              WHERE substantive_id = {$row['substantive_id']}
                                               AND rank_id = 7")
                                     ->fetch_assoc();
                if (!empty($genusRow['name_id'])) {
                    $genusFamilyName = $this->getFamily($genusRow['name_id']);
                    if (!empty($genusFamilyName)) {
                        return $genusFamilyName;
                    }
                }
            }
            // if we did not find any entry using the genus, continue with next reference
        } else {
            $rankId = $this->db->query("SELECT rank_id
                                               FROM mig_nom_name
                                               WHERE name_id = {$classification['scientific_name_id']}")
                               ->fetch_assoc()['rank_id'] ?? 0;
            while ($rankId != 9 && !empty($classification['parent_scientific_name_id'])) {
                $classification = $this->getClassification($classification['parent_scientific_name_id'], $sourceId);
                if (empty($classification)) {
                    break;
                }
                $rankId = $this->db->query("SELECT rank_id
                                                  FROM mig_nom_name
                                                  WHERE name_id = {$classification['scientific_name_id']}")
                                   ->fetch_assoc()['rank_id'] ?? 0;
            }
            // if no family ranked name was found, continue with next reference
            if (empty($rankId) || empty($classification) || ($rankId != 9 && empty($classification['parent_scientific_name_id']))) {
                continue;
            }
            $scientificName = $this->getScientificName($classification['scientific_name_id']);
            return array(
                "scientificNameId"       => $scientificName['scientific_name_id'] ?? '',
                "scientificName"         => $scientificName['scientific_name'] ?? '',
                "scientificNameNoAuthor" => $scientificName['scientific_name_no_author'] ?? '',
                "scientificNameAuthor"   => $scientificName['scientific_name_author'] ?? '',
                "source_id"              => $classification['source_id']
            );
        }
    }

    return null;    // we've found nothing
}

// ---------------------------------------
// ---------- private functions ----------
// ---------------------------------------

private function getClassification(int $scientificNameId, int $sourceId): bool|array|null
{
    $row = $this->db->query("SELECT classification_id, scientific_name_id, acc_scientific_name_id, parent_scientific_name_id, source_id
                             FROM tbl_classification 
                             WHERE scientific_name_id = $scientificNameId
                              AND source_id = $sourceId
                              AND source = 'CITATION'")
                    ->fetch_assoc();
    return $row ?? null;
}

private function getScientificName(int $scientificNameId): bool|array|null
{
    $row = $this->db->query("SELECT scientific_name_id, scientific_name, scientific_name_no_author, scientific_name_author
                             FROM view_scientificName
                             WHERE scientific_name_id = $scientificNameId")
                    ->fetch_assoc();
    return $row ?? null;
}

}
