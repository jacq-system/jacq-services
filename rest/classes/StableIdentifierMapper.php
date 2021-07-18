<?php
class StableIdentifierMapper extends Mapper
{

/**
 * get specimen-id of a given stable identifier
 */
public function getSpecimenID($sid)
{
    $row = $this->db->query("SELECT specimen_ID FROM tbl_specimens_stblid WHERE stableIdentifier = '" . $this->db->escape_string($sid) . "'")
                    ->fetch_assoc();
    if ($row) {
        return $row['specimen_ID'];
    } else {
        return 0;
    }
}

/**
 * get all stable identifiers and their respective timestamps of a given specimen-id
 */
public function getAllSid($specimenID)
{
    return $this->db->query("SELECT stableIdentifier, timestamp
                             FROM tbl_specimens_stblid
                             WHERE specimen_ID = '" . intval($specimenID) . "'
                             ORDER BY timestamp DESC")
                    ->fetch_all(MYSQLI_ASSOC);
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
                  'nextPage'     => $this->getBaseUrl() . 'multi?page=' . (($page < $lastPage) ? ($page + 1) : $lastpage) . '&entriesPerPage=' . $entriesPerPage,
                  'firstPage'    => $this->getBaseUrl() . 'multi?page=0&entriesPerPage=' . $entriesPerPage,
                  'lastPage'     => $this->getBaseUrl() . 'multi?page=' . $lastPage . '&entriesPerPage=' . $entriesPerPage,
                  'total'        => $result->num_rows,
                  );

    $rows = $this->db->query("SELECT specimen_ID AS specimenID, count(specimen_ID) AS `numberOfEntries`
                              FROM tbl_specimens_stblid
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