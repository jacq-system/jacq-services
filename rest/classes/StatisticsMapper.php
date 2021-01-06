<?php
class StatisticsMapper extends Mapper
{

/**
 * Get statistics result for given type, interval and period
 *
 * @param string $periodStart start of period (yyyy-mm-dd)
 * @param string $periodEnd end of period (yyyy-mm-dd)
 * @param int $updated new (0) or updated (1) types only
 * @param string $type type of statistics analysis (names, citations, names_citations, specimens, type_specimens, names_type_specimens, types_name, synonyms)
 * @param string $interval resolution of statistics analysis (day, week, month, year)
 * @return array found results
 */
public function getResults($periodStart, $periodEnd, $updated, $type, $interval)
{
//    $db = $this->getDbHerbarInputLog();

    $periodStartEscaped = $this->db->escape_string($periodStart);
    $periodEndEscaped = $this->db->escape_string($periodEnd);

    switch ($interval) {
        case 'day':
            $period = "dayofyear(l.timestamp) AS period";
            break;
        case 'year':
            $period = "year(l.timestamp) AS period";
            break;
        case 'month':
            $period = "month(l.timestamp) AS period";
            break;
        default :
            $period = "week(l.timestamp, 1) AS period";
            break;
    }

    $institutionOrder = $this->db->query("SELECT source_id, source_code FROM meta ORDER BY source_code")
                                 ->fetch_all(MYSQLI_ASSOC);

    switch ($type) {
        // New/Updated names per [interval] -> log_tax_species
        case 'names':
            $dbRows = $this->db->query("SELECT $period, count(l.taxonID) AS cnt, u.source_id
                                        FROM herbarinput_log.log_tax_species l, herbarinput_log.tbl_herbardb_users u, meta m
                                        WHERE l.userID = u.userID
                                         AND u.source_id = m.source_id
                                         AND l.updated = $updated
                                         AND l.timestamp >= '$periodStartEscaped'
                                         AND l.timestamp <= '$periodEndEscaped'
                                        GROUP BY period, u.source_id
                                        ORDER BY period")
                                ->fetch_all(MYSQLI_ASSOC);
            break;
        // New/Updated Citations per [Interval] -> log_lit
        case 'citations':
            $dbRows = $this->db->query("SELECT $period, count(l.citationID) AS cnt, u.source_id
                                        FROM herbarinput_log.log_lit l, herbarinput_log.tbl_herbardb_users u, meta m
                                        WHERE l.userID = u.userID
                                         AND u.source_id = m.source_id
                                         AND l.updated = $updated
                                         AND l.timestamp >= '$periodStartEscaped'
                                         AND l.timestamp <= '$periodEndEscaped'
                                        GROUP BY period, u.source_id
                                        ORDER BY period")
                                ->fetch_all(MYSQLI_ASSOC);
            break;
        // New/Updated Names used in Citations per [Interval] -> log_tax_index
        case 'names_citations':
            $dbRows = $this->db->query("SELECT $period, count(l.taxindID) AS cnt, u.source_id
                                        FROM herbarinput_log.log_tax_index l, herbarinput_log.tbl_herbardb_users u, meta m
                                        WHERE l.userID = u.userID
                                         AND u.source_id = m.source_id
                                         AND l.updated = $updated
                                         AND l.timestamp >= '$periodStartEscaped'
                                         AND l.timestamp <= '$periodEndEscaped'
                                        GROUP BY period, u.source_id
                                        ORDER BY period")
                                ->fetch_all(MYSQLI_ASSOC);
            break;
        // New/Updated Specimens per [Interval] -> log_specimens + (straight join) tbl_specimens + (straight join) tbl_management_collections
        case 'specimens':
            $dbRows = $this->db->query("SELECT $period, count(l.specimenID) AS cnt, mc.source_id
                                        FROM herbarinput_log.log_specimens l, tbl_specimens s, tbl_management_collections mc, meta m
                                        WHERE l.specimenID = s.specimen_ID
                                         AND s.collectionID = mc.collectionID
                                         AND mc.source_id = m.source_id
                                         AND l.updated = $updated
                                         AND l.timestamp >= '$periodStartEscaped'
                                         AND l.timestamp <= '$periodEndEscaped'
                                        GROUP BY period, mc.source_id
                                        ORDER BY period")
                                ->fetch_all(MYSQLI_ASSOC);
            break;
        // New/Updated Type-Specimens per [Interval] -> log_specimens + (straight join) tbl_specimens where typusID is not null + (straight join) tbl_management_collections
        case 'type_specimens':
            $dbRows = $this->db->query("SELECT $period, count(l.specimenID) AS cnt, mc.source_id
                                        FROM herbarinput_log.log_specimens l, tbl_specimens s, tbl_management_collections mc, meta m
                                        WHERE l.specimenID = s.specimen_ID
                                         AND s.collectionID = mc.collectionID
                                         AND mc.source_id = m.source_id
                                         AND s.typusID IS NOT NULL
                                         AND l.updated = $updated
                                         AND l.timestamp >= '$periodStartEscaped'
                                         AND l.timestamp <= '$periodEndEscaped'
                                        GROUP BY period, mc.source_id
                                        ORDER BY period")
                                ->fetch_all(MYSQLI_ASSOC);
            break;
        // New/Updated use of names for Type-Specimens per [Interval] -> log_specimens_types + (straight join) tbl_specimens + (straight join) tbl_management_collections
        case 'names_type_specimens':
            $dbRows = $this->db->query("SELECT $period, count(l.specimens_types_ID) AS cnt, mc.source_id
                                        FROM herbarinput_log.log_specimens_types l, tbl_specimens s, tbl_management_collections mc, meta m
                                        WHERE l.specimenID = s.specimen_ID
                                         AND s.collectionID = mc.collectionID
                                         AND mc.source_id = m.source_id
                                         AND l.updated = $updated
                                         AND l.timestamp >= '$periodStartEscaped'
                                         AND l.timestamp <= '$periodEndEscaped'
                                        GROUP BY period, mc.source_id
                                        ORDER BY period")
                                ->fetch_all(MYSQLI_ASSOC);
            break;
        // New/Updated Types per Name per [Interval] -> log_tax_typecollections
        case 'types_name':
            $dbRows = $this->db->query("SELECT $period, count(l.typecollID) AS cnt, u.source_id
                                        FROM herbarinput_log.log_tax_typecollections l, herbarinput_log.tbl_herbardb_users u, meta m
                                        WHERE l.userID = u.userID
                                         AND u.source_id = m.source_id
                                         AND l.updated = $updated
                                         AND l.timestamp >= '$periodStartEscaped'
                                         AND l.timestamp <= '$periodEndEscaped'
                                        GROUP BY period, u.source_id
                                        ORDER BY period")
                                ->fetch_all(MYSQLI_ASSOC);
            break;
        // New/Updated Synonyms per [Interval] -> log_tbl_tax_synonymy
        case 'synonyms':
            $dbRows = $this->db->query("SELECT $period, count(l.tax_syn_ID) AS cnt, u.source_id
                                        FROM herbarinput_log.log_tbl_tax_synonymy l, herbarinput_log.tbl_herbardb_users u, meta m
                                        WHERE l.userID = u.userID
                                         AND u.source_id = m.source_id
                                         AND l.updated = $updated
                                         AND l.timestamp >= '$periodStartEscaped'
                                         AND l.timestamp <= '$periodEndEscaped'
                                        GROUP BY period, u.source_id
                                        ORDER BY period")
                                ->fetch_all(MYSQLI_ASSOC);
            break;
        // New/Updated Classification entries per [Interval] -> table missing
        case 'classifications':
            $dbRows = array();
            break;
        default :
            $dbRows = array();
            break;
    }

    if (count($dbRows) > 0) {
        $result = array();

        // save source_codes of all institutions
        foreach ($institutionOrder as $institution) {
            $result['results'][$institution['source_id']]['source_code'] = $institution['source_code'];
        }

        $periodMin = $periodMax = $dbRows[0]['period'];
        // set every found statistics result in the respective column and row
        // and find the max and min values of the intervals
        foreach ($dbRows as $dbRow) {
            $periodMin = ($dbRow['period'] < $periodMin) ? $dbRow['period'] : $periodMin;
            $periodMax = ($dbRow['period'] > $periodMin) ? $dbRow['period'] : $periodMax;
            $result['results'][$dbRow['source_id']]['stat'][$dbRow['period']] = $dbRow['cnt'];
        }
        // set the remaining stats of every institution in every given interval with 0
        for ($i = $periodMin; $i <= $periodMax; $i++) {
            foreach ($institutionOrder as $institution) {
                if (empty($result['results'][$institution['source_id']]['stat'][$i])) {
                    $result['results'][$institution['source_id']]['stat'][$i] = 0;
                }
            }
        }
        // calculate totals
        foreach ($institutionOrder as $institution) {
            $result['results'][$institution['source_id']]['total'] = array_sum($result['results'][$institution['source_id']]['stat']);
        }
        $result['periodMin'] = $periodMin;
        $result['periodMax'] = $periodMax;
    } else {
        $result = array('periodMin' => 0, 'periodMax' => 0, 'results' => array());
    }

    return $result;
}

}