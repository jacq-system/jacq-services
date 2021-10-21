<?php
class ObjectsMapper extends Mapper
{

/**
 * get all properties of a specimen with given ID
 *
 * @param int $specimenID ID of specimen
 * @return array properties (dc, dwc and jacq)
 */
public function getSpecimenData($specimenID)
{
    $specimen = new SpecimenMapper($this->db, intval($specimenID));

//    return array_merge($specimen->getDC(), $specimen->getDWC(), $specimen->getJACQ());
    return array("dc"   => $specimen->getDC(),
                 "dwc"  => $specimen->getDWC(),
                 "jacq" => $specimen->getJACQ());
}

/**
 * get only properties with a value. null-values are loef out
 *
 * @param int $specimenID ID of specimen
 * @return array properties (dc, dwc and jacq)
 */
public function getSpecimenDataWithValues($specimenID)
{
    $data = $this->getSpecimenData($specimenID);
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
 * get all properties of a list of specimen
 *
 * @param array $list list of sepcimen-IDs
 * @return array properties (dc, dwc and jacq for each specimen)
 */
public function getSpecimensFromList($list)
{
    $result = array();
    foreach ($list as $item) {
        $result[] = $this->getSpecimenDataWithValues(intval($item));
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
 *      sort (sort order, default sciname, herbnr)
 *
 * @param array $params any parameters of the search
 * @param array[optional] $taxonIDList search for taxon terms has already finished, this is the list of results
 * @return type
 */
public function searchSpecimensList($params, $taxonIDList = array())
{
    // check if all allowed parameters are in order and set default values if any are missing
    $allowedParams = array('p'    => 0,                 // page, default: display first page
                           'rpp'  => 50,                // records per page, default: 50
                           'list' => 1,                 // return just a list of specimen-IDs?, default: yes
                           'term' => '',                // search for scientific name (joker = *)
                           'sc'   => '',                // search for a source-code
                           'coll' => '',                // search for a collector
                           'sort' => 'sciname,herbnr'   // sorting of result, default: order scinames and herbnumbers
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
    $constraint = "WHERE 1 ";
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
                case 'm':  $sql .= " LEFT JOIN tbl_management_collections mc ON mc.collectionID = s.collectionID
                                     LEFT JOIN meta m                        ON m.source_ID     = mc.source_ID ";  break;
                case 'c':  $sql .= " LEFT JOIN tbl_collector c               ON c.SammlerID     = s.SammlerID
                                     LEFT JOIN tbl_collector_2 c2            ON c2.Sammler_2ID  = s.Sammler_2ID "; break;
                case 'sn': $sql .= " LEFT JOIN tbl_tax_sciname sn            ON sn.taxonID      = s.taxonID ";     break;
                case 'ss': $sql .= " LEFT JOIN tbl_specimens_series ss       ON ss.seriesID     = s.seriesID ";    break;
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
private function getBaseUrl()
{
    $path = dirname(filter_input(INPUT_SERVER, 'SCRIPT_NAME', FILTER_SANITIZE_STRING));

    return filter_input(INPUT_SERVER, 'REQUEST_SCHEME', FILTER_SANITIZE_STRING) . "://"
         . filter_input(INPUT_SERVER, 'HTTP_HOST', FILTER_SANITIZE_STRING)
         . $path . '/';
}

}