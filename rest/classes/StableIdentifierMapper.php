<?php
class StableIdentifierMapper extends Mapper
{

/**
 * get specimen-id of a given stable identifier
 *
 * @param string $sid stable identifier to look for
 * @return int specimen-ID of stable identifier or 0 if nothing found
 */
public function getSpecimenID($sid)
{
    // sometimes double slashes get lost, so "https://" mutates to "https:/". Probably slim-related bug
    $sidCorr = str_replace(':///', '://', str_replace(':/', '://', $sid));

    $pos = strpos($sidCorr, "JACQID");
    if ($pos !== false) {  // we've found a sid with JACQID in it, so return the attached specimen-ID
        return intval(substr($sidCorr, $pos + 6));
    } else {
        $row = $this->db->query("SELECT specimen_ID 
                             FROM tbl_specimens_stblid 
                             WHERE stableIdentifier = '" . $this->db->escape_string($sidCorr) . "'")
                        ->fetch_assoc();
        if ($row) {
            return $row['specimen_ID'];
        } else {
            return 0;
        }
    }
}

/**
 * get all stable identifiers and their respective timestamps of a given specimen-id
 *
 * @param int $specimenID ID of specimen
 * @return array list of all stable identifiers
 */
public function getAllSid($specimenID)
{
    $ret['latest'] = $this->db->query("SELECT stableIdentifier, timestamp, CONCAT('https://www.jacq.org/detail.php?ID=', specimen_ID) AS link
                                       FROM tbl_specimens_stblid
                                       WHERE specimen_ID = '" . intval($specimenID) . "'
                                        AND stableIdentifier IS NOT NULL
                                       ORDER BY timestamp DESC
                                       LIMIT 1")
                              ->fetch_assoc();
    $ret['list'] = $this->db->query("SELECT stableIdentifier, timestamp, error
                                     FROM tbl_specimens_stblid
                                     WHERE specimen_ID = '" . intval($specimenID) . "'
                                     ORDER BY timestamp DESC")
                            ->fetch_all(MYSQLI_ASSOC);

    foreach ($ret['list'] as $key => $val) {
        if (!empty($val['error'])) {
            preg_match("/already exists \((?P<number>\d+)\)$/", $val['error'], $parts);
            $ret['list'][$key]['link'] = (!empty($parts['number'])) ? "https://www.jacq.org/detail.php?ID=" . $parts['number'] : '';
        }
    }

    return $ret;
}

/**
 * get a list of all specimens with multiple stable identifiers
 *
 * @param int $page optional page number, defaults to first page
 * @param int $entriesPerPage optional number of items, defaults to 50
 * @return array list of results
 */
public function getMultipleEntries($page = 0, $entriesPerPage = 0)
{
    if ($entriesPerPage <= 0) {
        $entriesPerPage = 50;
    } else if ($entriesPerPage > 100) {
        $entriesPerPage = 100;
    }

    $result = $this->db->query("SELECT specimen_ID AS specimenID, count(specimen_ID) AS `numberEntries`
                                FROM tbl_specimens_stblid
                                WHERE stableIdentifier IS NOT NULL
                                GROUP BY specimen_ID
                                HAVING numberEntries > 1");
    $lastPage = floor(($result->num_rows - 1) / $entriesPerPage);
    if ($page > $lastPage) {
        $page = $lastPage;
    } elseif ($page < 0) {
        $page = 0;
    }
    $data = array('page'         => $page + 1,
                  'previousPage' => $this->getBaseUrl() . 'multi?page=' . (($page > 0) ? ($page - 1) : 0) . '&entriesPerPage=' . $entriesPerPage,
                  'nextPage'     => $this->getBaseUrl() . 'multi?page=' . (($page < $lastPage) ? ($page + 1) : $lastPage) . '&entriesPerPage=' . $entriesPerPage,
                  'firstPage'    => $this->getBaseUrl() . 'multi?page=0&entriesPerPage=' . $entriesPerPage,
                  'lastPage'     => $this->getBaseUrl() . 'multi?page=' . $lastPage . '&entriesPerPage=' . $entriesPerPage,
                  'totalPages'   => $lastPage + 1,
                  'total'        => $result->num_rows,
                  );

    $rows = $this->db->query("SELECT specimen_ID AS specimenID, count(specimen_ID) AS `numberOfEntries`
                              FROM tbl_specimens_stblid
                              WHERE stableIdentifier IS NOT NULL
                              GROUP BY specimen_ID
                              HAVING numberOfEntries > 1
                              ORDER BY numberOfEntries DESC, specimenID
                              LIMIT " . ($entriesPerPage * $page) . ", $entriesPerPage")
                     ->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as $line => $row) {
        $data['result'][$line] = $row;
        $data['result'][$line]['stableIdentifierList'] = $this->getAllSid($row['specimenID']);
    }

    return $data;
}

/**
 * get a list of all specimens with multiple stable identifiers of a given source
 *
 * @param int $sourceID source-ID
 * @return array list of results
 */
public function getMultipleEntriesFromSource(int $sourceID)
{
    $rows = $this->db->query("SELECT ss.specimen_ID AS specimenID, count(ss.specimen_ID) AS `numberOfEntries`
                              FROM tbl_specimens_stblid ss
                               JOIN tbl_specimens s ON ss.specimen_ID = s.specimen_ID
                               JOIN tbl_management_collections mc ON s.collectionID = mc.collectionID
                              WHERE ss.stableIdentifier IS NOT NULL
                               AND mc.source_id = $sourceID
                              GROUP BY ss.specimen_ID
                              HAVING numberOfEntries > 1
                              ORDER BY numberOfEntries DESC, specimenID")
                     ->fetch_all(MYSQLI_ASSOC);
    $data = array('total' => count($rows));
    foreach ($rows as $line => $row) {
        $data['result'][$line] = $row;
        $data['result'][$line]['stableIdentifierList'] = $this->getAllSid($row['specimenID']);
    }

    return $data;
}

/**
 * get a list of all errors which prevent the generation of stable identifier
 *
 * @param int $sourceID optional source-ID, check only this source
 * @return array list of results
 */
public function getEntriesWithErrors($sourceID = 0)
{
    if (intval($sourceID)) {
        $rows = $this->db->query("SELECT ss.specimen_ID AS specimenID, CONCAT('https://www.jacq.org/detail.php?ID=', ss.specimen_ID) AS link
                              FROM tbl_specimens_stblid ss
                               JOIN tbl_specimens s ON ss.specimen_ID = s.specimen_ID
                               JOIN tbl_management_collections mc ON s.collectionID = mc.collectionID
                              WHERE ss.stableIdentifier IS NULL
                               AND mc.source_id = " . intval($sourceID) . "
                              GROUP BY ss.specimen_ID
                              ORDER BY ss.specimen_ID")
                         ->fetch_all(MYSQLI_ASSOC);
    } else {
        $rows = $this->db->query("SELECT specimen_ID AS specimenID, CONCAT('https://www.jacq.org/detail.php?ID=', specimen_ID) AS link
                              FROM tbl_specimens_stblid
                              WHERE stableIdentifier IS NULL
                              GROUP BY specimen_ID
                              ORDER BY specimen_ID")
                         ->fetch_all(MYSQLI_ASSOC);
    }
    $data = array('total' => count($rows));
    foreach ($rows as $line => $row) {
        $data['result'][$line] = $row;
        $data['result'][$line]['errorList'] = $this->getAllSid($row['specimenID'])['list'];
    }

    return $data;
}




////////////////////////////// private functions //////////////////////////////

/**
 * get the url of the actual directory to call the same service again
 * @return string url to service directory
 */
private function getBaseUrl()
{
    $path = dirname(filter_input(INPUT_SERVER, 'SCRIPT_NAME', FILTER_SANITIZE_STRING));

    return filter_input(INPUT_SERVER, 'REQUEST_SCHEME', FILTER_SANITIZE_STRING) . "://"
         . filter_input(INPUT_SERVER, 'HTTP_HOST', FILTER_SANITIZE_STRING)
         . $path . '/';
}

}
