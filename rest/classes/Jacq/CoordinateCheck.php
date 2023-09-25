<?php

namespace Jacq;

use mysqli;

class CoordinateCheck
{

private mysqli $db;

public function __construct(mysqli $db)
{
    $this->db = $db;
}

public function nationBoundaries(int $nationID, $lat, $lon)
{
    $lat = floatval($lat);
    $lon = floatval($lon);
    $boundaries = $this->db->query("SELECT bound_south, bound_north, bound_east, bound_west
                                    FROM tbl_geo_nation_geonames_boundaries
                                    WHERE nationID = $nationID")
                           ->fetch_all(MYSQLI_ASSOC);
    return array("nrBoundaries" => count($boundaries),
                 "inside"       => $this->checkBoundingBox(floatval($lat), floatval($lon), $boundaries));
}


private function checkBoundingBox($lat, $lon, $boundaries)
{
    foreach ($boundaries as $boundary) {
        if ($lat >= $boundary['bound_south'] && $lat <= $boundary['bound_north']
            && (($boundary['bound_east'] > $boundary['bound_west'] && ($lon >= $boundary['bound_west'] && $lon <= $boundary['bound_east']))
                || ($boundary['bound_east'] < $boundary['bound_west'] && ($lon <= $boundary['bound_west'] && $lon >= $boundary['bound_east'])))) {
            return true;
        }
    }
    return false;
}

}
