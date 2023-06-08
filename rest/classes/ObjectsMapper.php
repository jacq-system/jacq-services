<?php

use Jacq\HerbNummerScan;

class ObjectsMapper extends Mapper
{

/**
 * get all or some properties of a specimen with given ID
 *
 * @param int $specimenID ID of specimen
 * @param string $fieldgroups which groups should be returned (dc, dwc, jacq), defaults to all
 * @return array properties (dc, dwc and jacq)
 */
public function getSpecimenData(int $specimenID, string $fieldgroups = ''): array
{
    if (strpos($fieldgroups, "dc") === false && strpos($fieldgroups, "dwc") === false && strpos($fieldgroups, "jacq") === false) {
        $fieldgroups = "dc, dwc, jacq";
    }

    $specimen = new SpecimenMapper($this->db, $specimenID);

    $ret = array();
    if (strpos($fieldgroups, "dc") !== false) {
        $ret['dc'] = $specimen->getDC();
    }
    if (strpos($fieldgroups, "dwc") !== false) {
        $ret['dwc'] = $specimen->getDWC();
    }
    if (strpos($fieldgroups, "jacq") !== false) {
        $ret['jacq'] = $specimen->getJACQ();

        $imagelinks = new ImageLinkMapper($this->db, $specimenID);
        $ret['jacq']['jacq:image']         = $imagelinks->getImageLink();
        $ret['jacq']['jacq:downloadImage'] = $imagelinks->getFileLink();

    }
    return $ret;
//    return array_merge($specimen->getDC(), $specimen->getDWC(), $specimen->getJACQ());
//    return array("dc"   => $specimen->getDC(),
//                 "dwc"  => $specimen->getDWC(),
//                 "jacq" => $specimen->getJACQ());
}

/**
 * get only properties with a value. null-values are left out
 *
 * @param int $specimenID ID of specimen
 * @param string $fieldgroups which groups should be returned (dc, dwc, jacq), defaults to all
 * @return array properties (dc, dwc and jacq)
 */
public function getSpecimenDataWithValues(int $specimenID, string $fieldgroups = ''): array
{
    $data = $this->getSpecimenData($specimenID, $fieldgroups);
    $result = array();
    foreach ($data as $format => $group) {
        foreach ($group as $key => $value) {
            if (!empty($value)) {
                $result[$format][$key] = $value;
            }
        }
    }
    return $result;
}

    /**
     * TODO: change text
     * get all properties with a value of a list of specimen
     *
     * @param array $list list of sepcimen-IDs
     * @param string $fieldgroups which groups should be returned (dc, dwc, jacq), defaults to all
     * @return array properties (dc, dwc and jacq for each specimen)
     */
public function getSpecimensFromList(array $list, string $fieldgroups = ''): array
{
    $result = array();
    $alreadyFound = array();
    foreach ($list as $item) {
        $item = trim($item);
        if (is_numeric(substr($item, 0, 1))) {
            $specimenID = intval($item);
            if (!in_array($specimenID, $alreadyFound)) {
                $data = $this->getSpecimenDataWithValues($specimenID, $fieldgroups);
                if (!empty($data)) {
                    $alreadyFound[] = $specimenID;
                    $result[] = array_merge(["searchterm" => $specimenID], $data);
                } else {
                    $result[] = ["error" => "Identifier $specimenID not found"];
                }
            }
        } else {
            $herbnummer = new HerbNummerScan($this->db, $item);
            $specimenID = $this->getSpecimenIdFromHerbNummer($herbnummer->getHerbNummer(), $herbnummer->getSourceId());
            if ($specimenID) {
                if (!in_array($specimenID, $alreadyFound)) {
                    $data = $this->getSpecimenDataWithValues($specimenID, $fieldgroups);
                    if (!empty($data)) {
                        $alreadyFound[] = $specimenID;
                    }
                    $result[] = array_merge(["searchterm" => $item], $data);
                }
            } else {
                $result[] = ["error" => "Identifier $item not found"];
            }
        }
    }
    return $result;
}

/**
 * search for all specimens which fit given criteria
 * possible taxon-IDs have to be given as a list, as the search service for taxons need a special key and only the main rest-function has this key
 * params are all optional
 *      p (page to display, default first page),
 *      rpp (records per page, default 50),
 *      list (return just a list of specimen-IDs, default 1),
 *      term (search for taxon, default none),
 *      sc (source code, default none)
 *      coll (collector, default none)
 *      type (type records only, default 0)
 *      sort (sort order, default sciname, herbnr)
 *
 * @param array $params any parameters of the search
 * @param array $taxonIDList search for taxon terms has already finished, this is the list of results; defaults to empty array
 * @return array
 */
public function searchSpecimensList(array $params, array $taxonIDList = array()): array
{
    // check if all allowed parameters are in order and set default values if any are missing
    $allowedParams = array('p'      => 0,               // page, default: display first page
                           'rpp'    => 50,              // records per page, default: 50
                           'list'   => 1,               // return just a list of specimen-IDs?, default: yes
                           'term'   => '',              // search for scientific name (joker = *)
                           'sc'     => '',              // search for a source-code
                           'coll'   => '',              // search for a collector
                           'nation' => '',              // search for a nation
                           'type'   => 0,               // switch, search only for type records (default: no)
                           'sort'   => 'sciname,herbnr' // sorting of result, default: order scinames and herbnumbers
                          );
    foreach ($allowedParams as $key => $default) {
        $filteredParam[$key] = (isset($params[$key])) ? trim(filter_var($params[$key], FILTER_SANITIZE_STRING)) : $default;
    }
    $filteredParam['p'] = intval($filteredParam['p']);
    $filteredParam['rpp'] = intval($filteredParam['rpp']);

    // check if entries per page and page number are within allowed limits
    if ($filteredParam['rpp'] < 1) {
        $filteredParam['rpp'] = 1;
    } else if ($filteredParam['rpp'] > 100) {
        $filteredParam['rpp'] = 100;
    }
    if ($filteredParam['p'] < 0) {
        $filteredParam['p'] = 0;
    }

    // prepare the parts of the query string
    $sql = "SELECT SQL_CALC_FOUND_ROWS s.specimen_ID AS specimenID
            FROM tbl_specimens s ";
    $joins = array();
    $constraint = "WHERE s.accessible != '0' ";
    $order = "ORDER BY ";

    // what to search for
    if ($filteredParam['term']) {
        if ($taxonIDList) {
            $constraint .= " AND s.taxonID IN (" . implode(',', $taxonIDList) . ") ";
        } else { // there is no scientific name which fits the search criterea, so there can be no result
            $constraint .= " AND 0 ";
        }
    }
    if (!empty($filteredParam['sc'])) {
        $joins['m'] = true;
        $constraint .= " AND m.source_code LIKE '" . $this->db->real_escape_string($filteredParam['sc']) . "' ";
    }
    if (!empty($filteredParam['coll'])) {
        $joins['c'] = true;
        $valueE = $this->db->real_escape_string($filteredParam['coll']);
        $constraint .= " AND (c.Sammler LIKE '$valueE%' OR c2.Sammler_2 LIKE '%$valueE%') ";
    }
    if (!empty($filteredParam['nation'])) {
        $joins['gn'] = true;
        $nation = $this->db->real_escape_string($filteredParam['nation']);
        $constraint .= " AND (n.nation_engl LIKE '$nation' OR n.nation LIKE '$nation' OR n.nation_deutsch LIKE '$nation') ";
    }
    if (!empty($filteredParam['type'])) {
        $joins['tst'] = true;
        $constraint .= " AND tst.typusID IS NOT NULL ";
    }

    // order the result
    $parts = explode(',', $filteredParam['sort']);
    foreach ($parts as $part) {
        if (substr(trim($part), 0, 1) == '-') {
            $key = substr(trim($part), 1);
            $orderSequence = " DESC";
        } else {
            $key = trim($part);
            $orderSequence = '';
        }
        switch ($key) {
            case 'sciname':
                $joins['sn'] = true;
                $order .= "sn.scientificName{$orderSequence},";
                break;
            case 'coll':
                $joins['c'] = true;
                $order .= "c.Sammler{$orderSequence},c2.Sammler_2{$orderSequence},";
                break;
            case 'ser':
                $joins['ss'] = true;
                $order .= "ss.series{$orderSequence},";
                break;
            case 'num':
                $order .= "s.Nummer{$orderSequence},";
                break;
            case 'herbnr':
                $order .= "s.HerbNummer{$orderSequence},";
                break;
        }
    }
    $order .= "s.specimen_ID";  // as last resort, order according to specimen-ID

    // add all activated joins
    foreach ($joins as $join => $val) {
        if ($val) {
            switch ($join) {
                case 'tst': $sql .= " LEFT JOIN tbl_specimens_types tst       ON tst.specimenID  = s.specimen_ID "; break;
                case 'm':   $sql .= " LEFT JOIN tbl_management_collections mc ON mc.collectionID = s.collectionID
                                      LEFT JOIN meta m                        ON m.source_ID     = mc.source_ID ";  break;
                case 'gn':  $sql .= " LEFT JOIN tbl_geo_nation n              ON n.nationID      = s.NationID ";    break;
                case 'c':   $sql .= " LEFT JOIN tbl_collector c               ON c.SammlerID     = s.SammlerID
                                      LEFT JOIN tbl_collector_2 c2            ON c2.Sammler_2ID  = s.Sammler_2ID "; break;
                case 'sn':  $sql .= " LEFT JOIN tbl_tax_sciname sn            ON sn.taxonID      = s.taxonID ";     break;
                case 'ss':  $sql .= " LEFT JOIN tbl_specimens_series ss       ON ss.seriesID     = s.seriesID ";    break;
            }
        }
    }
    // do the actual query
    $list = $this->db->query($sql . $constraint . $order . " LIMIT " . ($filteredParam['rpp'] * $filteredParam['p']) . "," . $filteredParam['rpp'])
                     ->fetch_all(MYSQLI_ASSOC);

    // and get the total number of rows
    $nrRows = intval($this->db->query("SELECT FOUND_ROWS()")->fetch_row()[0]);

    // get the number of pages and check the active page again
    $lastPage = floor(($nrRows - 1) / $filteredParam['rpp']);
    if ($filteredParam['p'] > $lastPage) {   // if the page number was wrongly set to a too large value
        $filteredParam['p'] = $lastPage + 1; // reset it to the page after the last page
    }

    // prepare the output
    $p = $filteredParam['p'];    // save the filtered parameter
    unset($filteredParam['p']);  // so we can set the target page individually in the next lines
    $newparams = '&' . http_build_query($filteredParam, '', '&', PHP_QUERY_RFC3986);
    $url = $this->getBaseUrl() . 'specimens/search';
    $data = array('total'        => $nrRows,
                  'itemsPerPage' => $filteredParam['rpp'],
                  'page'         => $p + 1,
                  'previousPage' => $url . '?p=' . (($p > 0) ? ($p - 1) : 0) . $newparams,
                  'nextPage'     => $url . '?p=' . (($p < $lastPage) ? ($p + 1) : $lastPage) . $newparams,
                  'firstPage'    => $url . '?p=0' . $newparams,
                  'lastPage'     => $url . '?p=' . $lastPage . $newparams,
                  'totalPages'   => $lastPage + 1,
                  'result'       => array()
                  );
    foreach ($list as $item) {
        $data['result'][] = (!empty($filteredParam['list'])) ? intval($item['specimenID']) : $this->getSpecimenData($item['specimenID']);
    }

    return $data;
}












////////////////////////////// private functions //////////////////////////////

/**
 * get the url of the actual directory to call the same service again
 * @return string url to service directory
 */
private function getBaseUrl(): string
{
    $path = dirname(filter_input(INPUT_SERVER, 'SCRIPT_NAME', FILTER_SANITIZE_STRING));

    return filter_input(INPUT_SERVER, 'REQUEST_SCHEME', FILTER_SANITIZE_STRING) . "://"
         . filter_input(INPUT_SERVER, 'HTTP_HOST', FILTER_SANITIZE_STRING)
         . $path . '/';
}

/**
 * get the specimen-ID of a given HerbNumber and source-id
 *
 * @param string $herbNummer
 * @param int $source_id
 * @return int
 */
private function getSpecimenIdFromHerbNummer(string $herbNummer, int $source_id): int
{
    $row = $this->db->query("SELECT specimen_ID 
                             FROM tbl_specimens s 
                              LEFT JOIN tbl_management_collections mc on s.collectionID = mc.collectionID
                             WHERE s.HerbNummer = '$herbNummer'
                              AND mc.source_id = '$source_id'")
                -> fetch_assoc();
    if ($row) {
        return $row['specimen_ID'];
    } else {
        return 0;
    }
}

}
